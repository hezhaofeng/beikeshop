<?php

namespace Plugin\AdTracking;

use Beike\Models\Order;
use Beike\Services\StateMachineService;
use Illuminate\Support\Facades\DB;
use Plugin\AdTracking\Services\AttributionService;
use Plugin\AdTracking\Services\ConversionService;
use Plugin\AdTracking\Services\SettingsService;
use Plugin\AdTracking\Services\StatsService;
use Plugin\AdTracking\Services\TrackingEventService;

class Bootstrap
{
    /**
     * 注册广告归因、前端事件、服务端回传和后台订单来源展示。
     */
    public function boot(): void
    {
        $this->registerOrderFields();
        $this->registerAttribution();
        $this->registerTracker();
        $this->registerServerConversions();
        $this->registerAdminViews();
    }

    /**
     * 把插件字段加入 Order 的可写字段，兼容模型保存和后台更新。
     */
    private function registerOrderFields(): void
    {
        add_hook_filter('model.fillable:Beike\Models\Order', function (array $fillable): array {
            return array_merge($fillable, [
                'ad_tracking_source',
                'ad_tracking_medium',
                'ad_tracking_campaign',
                'ad_tracking_content',
                'ad_tracking_term',
                'ad_tracking_click_id',
                'ad_tracking_landing_url',
                'ad_tracking_referrer',
                'ad_tracking_data',
            ]);
        });
    }

    /**
     * 订单创建后写入归因字段并记录初始状态，保持订单主表和核心支付流程不变。
     */
    private function registerAttribution(): void
    {
        add_hook_filter('repository.order.create.after', function (array $data): array {
            $order = $data['order'] ?? null;
            if ($order instanceof Order) {
                AttributionService::applyToOrder($order);
                $this->dispatchServerConversions($order, StateMachineService::CREATED);
            }

            return $data;
        });
    }

    /**
     * 注入平台基础脚本，并为商品页、结账页和支付完成页输出标准事件。
     */
    private function registerTracker(): void
    {
        add_hook_blade('layout.header.code', function ($callback, $output, array $data = []): string {
            return view('AdTracking::shop.tracker', [
                'config' => SettingsService::publicConfig(),
            ])->render();
        }, 10);

        add_hook_blade('product.detail.before', function ($callback, $output, array $data = []): string {
            $viewData = $this->viewData($data);
            $product  = $viewData['product'] ?? [];

            return $product ? $this->eventView(TrackingEventService::product($product)) : '';
        }, 10);

        add_hook_blade('checkout.body.header', function ($callback, $output, array $data = []): string {
            $viewData = $this->viewData($data);

            return isset($viewData['carts']) ? $this->eventView(TrackingEventService::checkout($viewData)) : '';
        }, 10);

        add_hook_blade('checkout.success.footer', function ($callback, $output, array $data = []): string {
            $order = $this->viewData($data)['order'] ?? null;

            return $order instanceof Order && $this->isPurchaseStatus($order->status)
                ? $this->eventView(TrackingEventService::order($order))
                : '';
        }, 10);

        add_hook_blade('payment.footer', function ($callback, $output, array $data = []): string {
            $order = $this->viewData($data)['order'] ?? null;

            return $order instanceof Order && $this->isPurchaseStatus($order->status)
                ? $this->eventView(TrackingEventService::order($order))
                : '';
        }, 10);
    }

    /**
     * 在每次状态迁移后回传订单状态；Purchase 只在支付成功时发送，避免取消订单被误计为成交。
     */
    private function registerServerConversions(): void
    {
        add_hook_filter('service.state_machine.change_status.after', function (array $data): array {
            $order  = $data['order'] ?? null;
            $status = (string) ($data['status'] ?? '');
            if ($order instanceof Order) {
                $this->dispatchServerConversions($order, $status, $status === StateMachineService::PAID);
            }

            return $data;
        });
    }

    /**
     * 等待数据库事务提交后再调用外部平台，订单回滚时不会产生错误的广告事件。
     */
    private function dispatchServerConversions(Order $order, string $status, bool $sendPurchase = false): void
    {
        DB::afterCommit(static function () use ($order, $status, $sendPurchase): void {
            ConversionService::sendOrderStatus($order, $status);

            if ($sendPurchase) {
                ConversionService::sendPurchase($order);
            }
        });
    }

    /**
     * 在后台订单列表和详情展示来源，并向客户订单详情注入 Purchase 事件。
     */
    private function registerAdminViews(): void
    {
        add_hook_filter('admin.plugin.edit.data', function (array $data): array {
            $plugin = $data['plugin'] ?? null;
            if (($plugin->code ?? '') !== SettingsService::CODE) {
                return $data;
            }

            $data['adTracking'] = [
                'settings'  => SettingsService::all(),
                'platforms' => SettingsService::platforms(),
                'events'    => SettingsService::EVENTS,
                'stats'     => StatsService::dashboard(),
            ];

            return $data;
        });

        add_hook_blade('admin.order.list.item.th.total.after', function ($callback, $output, array $data = []): string {
            return view('AdTracking::admin.order_source_header')->render();
        }, 10);

        add_hook_blade('admin.order.list.filter.after', function ($callback, $output, array $data = []): string {
            return view('AdTracking::admin.order_filter')->render();
        }, 10);

        add_hook_blade('admin.order.index.vue.data', function ($callback, $output, array $data = []): string {
            return "ad_source: bk.getQueryString('ad_source'),\n          ad_campaign: bk.getQueryString('ad_campaign'),";
        }, 10);

        add_hook_blade('admin.order.list.item.td.total.after', function ($callback, $output, array $data = []): string {
            $order = $data['data']['order'] ?? null;

            return view('AdTracking::admin.order_source_cell', ['order' => $order])->render();
        }, 10);

        add_hook_filter('admin.order.repo.list.builder.after', function ($builder) {
            if (request('ad_source')) {
                $builder->where('ad_tracking_source', request('ad_source'));
            }
            if (request('ad_campaign')) {
                $builder->where('ad_tracking_campaign', 'like', '%' . request('ad_campaign') . '%');
            }

            return $builder;
        }, 20);

        add_hook_filter('admin.order.show.data', function (array $data): array {
            $order = $data['order'] ?? null;
            if ($order instanceof Order) {
                $data['html_items'][] = view('AdTracking::admin.order_source', ['order' => $order])->render();
            }

            return $data;
        });

        foreach (['account.order.show.data', 'order.show.data'] as $hook) {
            add_hook_filter($hook, function (array $data): array {
                $order = $data['order'] ?? null;
                if ($order instanceof Order && $this->isPurchaseStatus($order->status)) {
                    $data['html_items'][] = $this->eventView(TrackingEventService::order($order));
                }

                return $data;
            });
        }
    }

    /**
     * 从 Blade Hook 传入的 data 包装中取得当前视图变量。
     */
    private function viewData(array $data): array
    {
        return is_array($data['data'] ?? null) ? $data['data'] : $data;
    }

    /**
     * 渲染可重复使用的前端事件脚本。
     */
    private function eventView(array $event): string
    {
        if (! SettingsService::eventEnabled((string) ($event['name'] ?? ''))) {
            return '';
        }

        return view('AdTracking::shop.event', ['event' => $event])->render();
    }

    /**
     * 只有有效成交状态才发送 Purchase，避免待支付订单提前计入转化。
     */
    private function isPurchaseStatus(?string $status): bool
    {
        return in_array($status, [
            StateMachineService::PAID,
            StateMachineService::SHIPPED,
            StateMachineService::COMPLETED,
        ], true);
    }
}
