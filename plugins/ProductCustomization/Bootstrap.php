<?php

namespace Plugin\ProductCustomization;

use Illuminate\Database\Eloquent\Model;
use Plugin\ProductCustomization\Services\CustomizationService;

/**
 * 商品定制插件启动入口。
 */
class Bootstrap
{
    public function boot(): void
    {
        $this->registerAdminHooks();
        $this->registerProductHooks();
        $this->registerCartHooks();
        $this->registerOrderHooks();
    }

    private function registerAdminHooks(): void
    {
        add_hook_filter('admin.sidebar.product_routes', function (array $routes): array {
            $routes[] = [
                'route'    => 'product_customization.index',
                'prefixes' => ['product_customization'],
                'title'    => '商品定制',
            ];

            return $routes;
        });

        add_hook_blade('admin.plugin.form.after', function ($callback, $output, array $data = []): string {
            $plugin = $data['plugin'] ?? null;
            if (! $plugin || $plugin->code !== CustomizationService::CODE) {
                return $output;
            }

            return $output . view('ProductCustomization::admin.plugin_link')->render();
        });
    }

    private function registerProductHooks(): void
    {
        add_hook_filter('product.show.data', function (array $data): array {
            $productId = (int) data_get($data, 'product.id', 0);
            if ($productId > 0) {
                $data['product']['product_customization'] = app(CustomizationService::class)->frontendData($productId);
            }

            return $data;
        });

        add_hook_blade('product.detail.buy.before', function ($callback, $output, array $data = []): string {
            $customization = data_get($data, 'product.product_customization');
            if (! $customization) {
                return $output;
            }

            return $output . view('ProductCustomization::shop.fields', compact('customization'))->render();
        });

        add_hook_blade('product.detail.vue.data', function ($callback, $output, array $data = []): string {
            $customization = data_get($data, 'product.product_customization');
            if (! $customization) {
                return $output;
            }

            $fields = $customization['fields'] ?? [];
            $values = array_fill_keys(array_column($fields, 'key'), '');

            return $output
                . 'customizationFields: ' . $this->jsonForScript($fields) . ",\n"
                . 'customizationValues: ' . $this->jsonForScript($values) . ",\n"
                . 'customizationRequiredMessage: ' . $this->jsonForScript(__('ProductCustomization::common.field_required', ['name' => '__field__'])) . ",\n";
        });

        add_hook_blade('product.detail.vue.methods', function ($callback, $output, array $data = []): string {
            if (! data_get($data, 'product.product_customization')) {
                return $output;
            }

            return $output . <<<'JS'
        beforeAddCartHooks(params) {
          const values = {};
          for (const field of this.customizationFields) {
            const value = String(this.customizationValues[field.key] ?? '').trim();
            if (field.required && !value) {
              layer.msg(this.customizationRequiredMessage.replace('__field__', field.label));
              return false;
            }
            values[field.key] = value;
          }
          params.customization = values;
          return params;
        },
JS;
        });
    }

    private function registerCartHooks(): void
    {
        add_hook_filter('cart.store.context', function (array $context): array {
            return app(CustomizationService::class)->buildCartContext($context);
        });

        add_hook_action('cart.store.after', function (array $data): void {
            app(CustomizationService::class)->saveCartCustomization(
                $data['cart']                     ?? null,
                $data['context']['customization'] ?? null
            );
        });

        add_hook_filter('resource.cart.detail', function (array $data): array {
            $summary                       = app(CustomizationService::class)->cartSummary((int) ($data['cart_id'] ?? 0));
            $data['customization_summary'] = $summary;
            if ($summary) {
                $data['variant_labels'] = trim($data['variant_labels'] . ' | ' . __('ProductCustomization::common.summary_prefix') . $summary, ' |');
            }

            return $data;
        });
    }

    private function registerOrderHooks(): void
    {
        add_hook_filter('repository.order_product.create.after', function (array $data): array {
            app(CustomizationService::class)->copyToOrder($data);

            return $data;
        });

        foreach (['order_info.product_name.after', 'account.order.list.name.after'] as $hook) {
            add_hook_blade($hook, function ($callback, $output, $data): string {
                $orderProduct = $this->modelFromHookData($data);
                if (! $orderProduct) {
                    return $output;
                }

                $summary = app(CustomizationService::class)->orderSummary((int) $orderProduct->id);

                return $summary ? $output . '<div class="text-muted small">' . e(__('ProductCustomization::common.summary_prefix')) . e($summary) . '</div>' : $output;
            });
        }

        foreach (['account.order.show.data', 'order.show.data', 'admin.order.show.data'] as $hook) {
            add_hook_filter($hook, function (array $data): array {
                $order = $data['order'] ?? null;
                if (! $order) {
                    return $data;
                }

                $rows = [];
                foreach ($order->orderProducts as $orderProduct) {
                    $summary = app(CustomizationService::class)->orderSummary((int) $orderProduct->id);
                    if ($summary) {
                        $rows[] = ['name' => $orderProduct->name, 'summary' => $summary];
                    }
                }
                if ($rows) {
                    $data['html_items'][] = view('ProductCustomization::order.summary', compact('rows'))->render();
                }

                return $data;
            });
        }
    }

    private function modelFromHookData(mixed $data): ?Model
    {
        if ($data instanceof Model) {
            return $data;
        }
        if (is_array($data)) {
            foreach (['order_product', 'product'] as $key) {
                if (($data[$key] ?? null) instanceof Model) {
                    return $data[$key];
                }
            }
        }

        return null;
    }

    private function jsonForScript(mixed $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }
}
