<?php

namespace Beike\Admin\Http\Controllers;

use Beike\Models\CartAddEvent;
use Beike\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CartObservationController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'keyword' => ['nullable', 'string', 'max:100'],
            'visitor' => ['nullable', Rule::in(['all', 'customer', 'guest'])],
            'from'    => ['nullable', 'date_format:Y-m-d'],
            'to'      => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $filters = array_merge([
            'visitor' => 'all',
            'from'    => now()->subDays(29)->toDateString(),
            'to'      => now()->toDateString(),
        ], $filters);
        $filters['visitor'] = $filters['visitor'] ?: 'all';
        $filters['from']    = $filters['from'] ?: now()->subDays(29)->toDateString();
        $filters['to']      = $filters['to'] ?: now()->toDateString();

        $events = CartAddEvent::query()
            ->where('created_at', '>=', $filters['from'] . ' 00:00:00')
            ->where('created_at', '<=', $filters['to'] . ' 23:59:59');

        if ($filters['visitor'] === 'customer') {
            $events->whereNotNull('customer_id');
        } elseif ($filters['visitor'] === 'guest') {
            $events->whereNull('customer_id');
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim($filters['keyword']);
            $events->where(function ($query) use ($keyword): void {
                $query->where('product_name', 'like', '%' . $keyword . '%')
                    ->orWhere('product_sku', 'like', '%' . $keyword . '%')
                    ->orWhere('sku_model', 'like', '%' . $keyword . '%')
                    ->orWhere('visitor_key', 'like', '%' . $keyword . '%')
                    ->orWhereHas('customer', function ($customerQuery) use ($keyword): void {
                        $customerQuery->withTrashed()
                            ->where('name', 'like', '%' . $keyword . '%')
                            ->orWhere('email', 'like', '%' . $keyword . '%');
                    });
            });
        }

        $identityExpression = "CASE WHEN customer_id IS NULL THEN CONCAT('guest:', visitor_key) ELSE CONCAT('customer:', customer_id) END";
        $groups             = (clone $events)
            ->selectRaw("{$identityExpression} AS identity_key")
            ->selectRaw('MAX(id) AS latest_event_id, MAX(created_at) AS last_added_at, SUM(quantity) AS total_quantity, COUNT(*) AS add_count')
            ->groupBy('identity_key')
            ->orderByDesc('latest_event_id')
            ->paginate(30)
            ->withQueryString();

        $identityKeys = collect($groups->items())->pluck('identity_key');
        $customerIds  = $identityKeys
            ->filter(fn (string $key): bool => str_starts_with($key, 'customer:'))
            ->map(fn (string $key): int => (int) substr($key, strlen('customer:')))
            ->all();
        $visitorKeys = $identityKeys
            ->filter(fn (string $key): bool => str_starts_with($key, 'guest:'))
            ->map(fn (string $key): string => substr($key, strlen('guest:')))
            ->all();

        $customers = Customer::withTrashed()
            ->whereIn('id', $customerIds)
            ->get()
            ->keyBy('id');

        $products = collect();
        if ($customerIds || $visitorKeys) {
            $products = (clone $events)
                ->where(function (Builder $query) use ($customerIds, $visitorKeys): void {
                    if ($customerIds) {
                        $query->whereIn('customer_id', $customerIds);
                    }
                    if ($visitorKeys) {
                        $method = $customerIds ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('visitor_key', $visitorKeys);
                    }
                })
                ->selectRaw("{$identityExpression} AS identity_key")
                ->selectRaw('product_id, product_name, product_sku, sku_model, SUM(quantity) AS quantity, COUNT(*) AS add_count, MAX(created_at) AS last_added_at')
                ->groupBy('identity_key', 'product_id', 'product_name', 'product_sku', 'sku_model')
                ->orderByDesc('last_added_at')
                ->get()
                ->groupBy('identity_key');
        }

        $catalogRoutes = app(\Plugin\CyberCloak\Services\CatalogRouteService::class);
        $urlIds        = $products->flatten(1)
            ->pluck('product_id')
            ->unique()
            ->mapWithKeys(fn ($productId): array => [
                (int) $productId => $catalogRoutes->urlIdForRealRecord('product', (int) $productId),
            ]);

        $groups->setCollection(collect($groups->items())->map(function ($group) use ($customers, $products, $urlIds): array {
            $isCustomer = str_starts_with($group->identity_key, 'customer:');
            $identifier = substr($group->identity_key, $isCustomer ? strlen('customer:') : strlen('guest:'));
            $customer   = $isCustomer ? $customers->get((int) $identifier) : null;

            return [
                'identity_key'     => $group->identity_key,
                'customer_id'      => $isCustomer ? (int) $identifier : null,
                'customer_name'    => $customer?->name,
                'customer_email'   => $customer?->email,
                'deleted_customer' => $isCustomer && ! $customer,
                'visitor_key'      => $isCustomer ? null : $identifier,
                'last_added_at'    => (string) $group->last_added_at,
                'total_quantity'   => (int) $group->total_quantity,
                'add_count'        => (int) $group->add_count,
                'product_count'    => $products->get($group->identity_key, collect())->pluck('product_id')->unique()->count(),
                'products'         => $products->get($group->identity_key, collect())->map(function ($product) use ($urlIds): array {
                    return [
                        'product_id'    => (int) $product->product_id,
                        'product_name'  => (string) $product->product_name,
                        'product_sku'   => (string) $product->product_sku,
                        'sku_model'     => (string) $product->sku_model,
                        'quantity'      => (int) $product->quantity,
                        'add_count'     => (int) $product->add_count,
                        'last_added_at' => (string) $product->last_added_at,
                        'url'           => ($urlIds[(int) $product->product_id] ?? 0) > 0
                            ? shop_route('products.show', ['product' => $urlIds[(int) $product->product_id]])
                            : '',
                    ];
                })->values()->all(),
            ];
        }));

        return view('admin::pages.customer_carts.index', compact('groups', 'filters'));
    }
}
