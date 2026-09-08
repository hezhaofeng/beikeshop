<?php

namespace Plugin\CustomMail\Services;

use Beike\Mail\CustomerNewOrder;
use Beike\Models\Order;
use Beike\Models\Rma;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Plugin\CustomMail\Mail\CustomTemplateMail;

class CustomMailService
{
    public const CODE = 'custom_mail';

    /** 仅允许服务端生成的订单片段以 HTML 形式插入正文。 */
    private const SAFE_HTML_VARIABLES = [
        'order_products_html',
        'order_totals_html',
        'shipping_address_html',
        'view_order_button_html',
        'payment_info_html',
    ];

    /** 支付信息片段仅对这两种人工转账方式生效。 */
    public const PAYMENT_INFO_PAYMENT_CODES = [
        'offline_transfer',
        'western_union',
    ];

    /** 待支付类邮件允许配置支付方式专用模板的支付方式。 */
    public const PAYMENT_METHOD_TEMPLATE_CODES = self::PAYMENT_INFO_PAYMENT_CODES;

    public const PAYMENT_INFO_PLACEHOLDER = '{{payment_info_html}}';

    public const PAYMENT_REMINDER_EVENT = 'order_payment_reminder';

    /** 支持按支付方式覆盖的待支付邮件场景。 */
    public const PAYMENT_METHOD_TEMPLATE_EVENTS = [
        'order_status_unpaid',
        self::PAYMENT_REMINDER_EVENT,
    ];

    /** 需要在关闭系统订单邮件时补发原生订单确认邮件的线下支付方式。 */
    public const NATIVE_ORDER_CONFIRMATION_PAYMENT_CODES = [
        'offline_transfer',
        'western_union',
    ];

    /** 后台展示顺序，同时也是可用的事件白名单。 */
    public const EVENTS = [
        'customer_registered'    => '客户注册成功',
        'order_created'          => '订单创建',
        'order_status_unpaid'    => '订单待支付',
        'order_payment_reminder' => '待支付催款（手动）',
        'order_status_paid'      => '订单已支付',
        'order_status_shipped'   => '订单已发货',
        'order_status_completed' => '订单已完成',
        'order_status_cancelled' => '订单已取消',
        'order_status_refunding' => '订单退款中',
        'rma_created'            => '售后申请创建',
    ];

    public function sendAfterCommit(string $event, mixed $payload, array $extra = []): void
    {
        // 注册、RMA 等流程可能没有开启事务；没有事务时直接发送，避免 afterCommit 抛出异常阻断业务。
        if (DB::connection()->transactionLevel() === 0) {
            $this->send($event, $payload, $extra);

            return;
        }

        DB::afterCommit(function () use ($event, $payload, $extra): void {
            $this->send($event, $payload, $extra);
        });
    }

    public function send(string $event, mixed $payload, array $extra = []): bool
    {
        if (! array_key_exists($event, self::EVENTS)) {
            return false;
        }

        $settings = $this->settings();
        $template = in_array($event, self::PAYMENT_METHOD_TEMPLATE_EVENTS, true)
            ? $this->paymentMethodTemplate($settings, $event, $payload instanceof Order ? $payload : null)
            : ($settings['templates'][$event] ?? []);
        if (! $this->enabled($settings, $event) || ! is_array($template)) {
            return false;
        }

        $variables = $this->variables($payload, $extra);
        if ($payload instanceof Order && str_contains((string) ($template['body'] ?? ''), self::PAYMENT_INFO_PLACEHOLDER)) {
            $variables['payment_info_html'] = new HtmlString(
                $this->paymentInfoHtml($payload, $settings, $variables)
            );
        }
        $subject   = $this->render((string) ($template['subject'] ?? ''), $variables);
        $body      = $this->sanitizeHtml($this->render((string) ($template['body'] ?? ''), $variables));
        if ($subject === '' || $body === '') {
            return false;
        }

        $recipients = $this->recipients($settings, $template, $payload);
        if ($recipients === []) {
            return false;
        }

        $mail = new CustomTemplateMail($subject, $body);

        try {
            $pending = $this->toBool(system_setting('base.use_queue', true));
            foreach ($recipients as $recipient) {
                $message = clone $mail;
                $pending ? Mail::to($recipient)->queue($message) : Mail::to($recipient)->send($message);
            }

            return true;
        } catch (\Throwable $e) {
            // 不阻断注册、下单或状态迁移；失败详情由 Laravel 邮件日志记录。
            Log::error('自定义邮件发送失败', ['event' => $event, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function settings(): array
    {
        $settings = plugin_setting(self::CODE, []);

        return is_array($settings) ? $settings : [];
    }

    public function enabled(array $settings, string $event): bool
    {
        return $this->toBool($settings['status'] ?? false)
            && in_array($event, (array) ($settings['enabled_events'] ?? []), true);
    }

    /**
     * Offline Transfer 和 Western Union 下单后，补发原生 Customer New Order 邮件。
     *
     * 系统订单邮件已启用时，状态机已投递相同 Mailable，这里必须跳过以避免双发。
     */
    public function sendNativeOrderConfirmationForTransferPaymentAfterCommit(Order $order): void
    {
        $settings = $this->settings();
        if ($this->enabled($settings, 'order_status_unpaid')) {
            return;
        }

        if (! $this->shouldSendNativeOrderConfirmationForTransferPayment(
            (string) $order->payment_method_code,
            system_setting('base.mail_engine'),
            system_setting('base.mail_customer', [])
        )) {
            return;
        }

        $send = function () use ($order): void {
            $this->sendNativeOrderConfirmation($order);
        };

        if (DB::connection()->transactionLevel() === 0) {
            $send();

            return;
        }

        DB::afterCommit($send);
    }

    /**
     * 判断是否需补发：仅限指定支付方式，且系统原生客户订单邮件未启用。
     */
    public function shouldSendNativeOrderConfirmationForTransferPayment(
        string $paymentMethodCode,
        mixed $mailEngine,
        mixed $customerMailEvents
    ): bool {
        if (! in_array($paymentMethodCode, self::NATIVE_ORDER_CONFIRMATION_PAYMENT_CODES, true)) {
            return false;
        }

        return ! $this->coreCustomerOrderMailEnabled($mailEngine, $customerMailEvents);
    }

    public function coreCustomerOrderMailEnabled(mixed $mailEngine, mixed $customerMailEvents): bool
    {
        return (bool) $mailEngine && in_array('order', (array) $customerMailEvents, true);
    }

    /**
     * 根据受支持的线下支付方式选择待支付类模板。
     *
     * 旧版场景模板仍作为通用模板；专用模板只有在主题和正文都填写后才生效，
     * 其他支付方式始终回退到通用模板。
     */
    public function paymentMethodTemplate(array $settings, string $event, mixed $order = null): array
    {
        $generic          = data_get($settings, 'templates.' . $event, []);
        $generic          = is_array($generic) ? $generic : [];
        $paymentTemplates = $generic['payment_methods'] ?? [];
        unset($generic['payment_methods']);

        $paymentCode = trim((string) data_get($order, 'payment_method_code', ''));
        $specific    = is_array($paymentTemplates)
            && in_array($paymentCode, self::PAYMENT_METHOD_TEMPLATE_CODES, true)
            ? ($paymentTemplates[$paymentCode] ?? null)
            : null;

        if (is_array($specific)
            && trim((string) ($specific['subject'] ?? '')) !== ''
            && trim((string) ($specific['body'] ?? ''))    !== '') {
            return $specific;
        }

        return $generic;
    }

    /** 保留旧调用方兼容性。 */
    public function paymentReminderTemplate(array $settings, mixed $order = null): array
    {
        return $this->paymentMethodTemplate($settings, self::PAYMENT_REMINDER_EVENT, $order);
    }

    /**
     * 直接复用原生 Mailable 和视图，确保邮件主题、正文、订单语言完全一致。
     */
    private function sendNativeOrderConfirmation(Order $order): bool
    {
        $recipient = trim((string) $order->email);
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            Log::warning('原生订单确认邮件未发送：订单邮箱无效', [
                'order_number' => $order->number,
                'payment_code' => $order->payment_method_code,
            ]);

            return false;
        }

        try {
            // CustomerNewOrder 实现 ShouldQueue，沿用原生订单邮件的异步投递方式。
            Mail::to($recipient)->queue(new CustomerNewOrder($order));

            return true;
        } catch (\Throwable $e) {
            Log::error('原生订单确认邮件发送失败', [
                'order_number' => $order->number,
                'payment_code' => $order->payment_method_code,
                'error'        => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** 将模板中的白名单占位符替换为纯文本值，避免执行 Blade/PHP。 */
    public function render(string $template, array $variables): string
    {
        $replace = [];
        foreach ($variables as $key => $value) {
            $replace['{{' . $key . '}}'] = in_array($key, self::SAFE_HTML_VARIABLES, true) && $value instanceof HtmlString
                ? $value->toHtml()
                : htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return trim(strtr($template, $replace));
    }

    public function variables(mixed $payload, array $extra = []): array
    {
        $variables = [
            'store_name'          => (string) system_setting('base.store_name', config('app.name')),
            'customer_name'       => '', 'customer_email' => '', 'order_number' => '', 'payment_url' => '',
            'order_view_url'      => '', 'view_order_button_html' => '',
            'order_status'        => '', 'order_total' => '', 'order_date' => '',
            'shipping_method'     => '', 'payment_method' => '', 'rma_id' => '',
            'rma_status'          => '', 'rma_product' => '', 'rma_quantity' => '',
            'order_products_html' => '', 'order_totals_html' => '', 'shipping_address_html' => '',
            'shipping_telephone'  => '',
            'payment_info_html'   => '',
            'comment'             => (string) ($extra['comment'] ?? ''),
        ];
        if ($payload instanceof Order) {
            $variables['customer_name']          = $this->customerName($payload);
            $variables['customer_email']         = (string) ($payload->email ?? '');
            $variables['order_number']           = (string) ($payload->number ?? '');
            $variables['order_status']           = (string) ($payload->status_format ?? $payload->status ?? '');
            $variables['order_total']            = (string) ($payload->total_format ?? $payload->total ?? '');
            $variables['order_date']             = (string) ($payload->created_at ?? '');
            $variables['shipping_method']        = (string) ($payload->shipping_method_name ?? '');
            $variables['payment_method']         = (string) ($payload->payment_method_name ?? '');
            $variables['order_products_html']    = new HtmlString($this->orderProductsHtml($payload));
            $variables['order_totals_html']      = new HtmlString($this->orderTotalsHtml($payload));
            $variables['shipping_address_html']  = new HtmlString($this->shippingAddressHtml($payload));
            $variables['shipping_telephone']     = (string) ($payload->shipping_telephone ?? '');
            $variables['order_view_url']         = $this->orderViewUrl($payload);
            $variables['view_order_button_html'] = new HtmlString($this->viewOrderButtonHtml($payload));
            $variables['payment_url']            = $this->paymentUrl($payload);
        } elseif ($payload instanceof Rma) {
            $variables['customer_name']  = (string) ($payload->name ?? '');
            $variables['customer_email'] = (string) ($payload->email ?? '');
            $variables['rma_id']         = (string) ($payload->id ?? '');
            $variables['rma_status']     = (string) ($payload->status_format ?? $payload->status ?? '');
            $variables['rma_product']    = (string) ($payload->product_name ?? '');
            $variables['rma_quantity']   = (string) ($payload->quantity ?? '');
            $variables['order_number']   = (string) ($payload->order?->number ?? '');
            $variables['comment']        = (string) ($payload->comment ?? $variables['comment']);
        } else {
            $variables['customer_name']  = (string) ($payload->name ?? '');
            $variables['customer_email'] = (string) ($payload->email ?? '');
        }

        return $variables;
    }

    /**
     * 根据订单支付方式选择并渲染 CustomMail 中配置的支付 HTML 片段。
     * 首次发送时保存片段 HTML 快照；后续邮件复用，避免同一订单出现不同收款信息。
     */
    public function paymentInfoHtml(mixed $order, array $settings, array $variables = []): string
    {
        $paymentCode = trim((string) $order->payment_method_code);
        if (! in_array($paymentCode, self::PAYMENT_INFO_PAYMENT_CODES, true)) {
            return '';
        }

        $config = data_get($settings, 'payment_info.' . $paymentCode, []);
        if (! is_array($config)) {
            return '';
        }

        $profiles = [];
        foreach ((array) ($config['profiles'] ?? []) as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $id   = trim((string) ($profile['id'] ?? ''));
            $html = trim((string) ($profile['html'] ?? ''));
            if ($id === '' || $html === '' || ! $this->toBool($profile['enabled'] ?? false)) {
                continue;
            }

            $profiles[] = [
                'id'   => $id,
                'name' => trim((string) ($profile['name'] ?? $id)),
                'html' => $html,
            ];
        }
        if ($profiles === []) {
            return '';
        }

        $strategy = ($config['strategy'] ?? 'manual') === 'round_robin' ? 'round_robin' : 'manual';
        $selected = $order instanceof Order
            ? $this->assignedPaymentInfoProfile($order, $paymentCode, $profiles, $strategy, $config)
            : $this->selectPaymentInfoProfile($profiles, $strategy, $config, data_get($order, 'number'));
        if (! is_array($selected)) {
            return '';
        }

        $rendered = $this->render($selected['html'], $variables);

        return $this->sanitizeHtml($rendered);
    }

    /**
     * 为订单创建或读取支付信息分配。轮询状态在独立表中锁定更新，避免并发重复分配。
     */
    private function assignedPaymentInfoProfile(
        Order $order,
        string $paymentCode,
        array $profiles,
        string $strategy,
        array $config
    ): ?array {
        try {
            return DB::transaction(function () use ($order, $paymentCode, $profiles, $strategy, $config): ?array {
                $state = null;
                if ($strategy === 'round_robin') {
                    // 先锁轮询状态，再检查订单分配，保证同一订单并发发送只消费一次轮询次数。
                    $state = DB::table('custom_mail_payment_info_rotation_states')
                        ->where('payment_method_code', $paymentCode)
                        ->lockForUpdate()
                        ->first();
                    if (! $state) {
                        throw new \RuntimeException("支付信息轮询状态未初始化：{$paymentCode}");
                    }
                }

                $assignment = DB::table('custom_mail_payment_info_assignments')
                    ->where('order_id', $order->id)
                    ->where('payment_method_code', $paymentCode)
                    ->lockForUpdate()
                    ->first();
                if ($assignment) {
                    return [
                        'id'   => $assignment->profile_id,
                        'name' => $assignment->profile_name,
                        'html' => $assignment->profile_html,
                    ];
                }

                $selected = $this->selectPaymentInfoProfile($profiles, $strategy, $config, null, true);
                if (! $selected) {
                    return null;
                }

                if ($strategy === 'round_robin') {
                    $batchSize = $this->rotationBatchSize($config);
                    $position  = $this->rotationProfileIndex((int) $state->next_position, $batchSize, count($profiles));
                    $selected  = $profiles[$position];
                    DB::table('custom_mail_payment_info_rotation_states')
                        ->where('payment_method_code', $paymentCode)
                        ->update([
                            'next_position' => (int) $state->next_position + 1,
                            'updated_at'    => now(),
                        ]);
                }

                DB::table('custom_mail_payment_info_assignments')->insert([
                    'order_id'            => $order->id,
                    'payment_method_code' => $paymentCode,
                    'profile_id'          => $selected['id'],
                    'profile_name'        => $selected['name'],
                    'profile_html'        => $selected['html'],
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);

                return $selected;
            });
        } catch (\Throwable $exception) {
            Log::error('支付信息片段分配失败', [
                'order_id'     => $order->id,
                'payment_code' => $paymentCode,
                'error'        => $exception->getMessage(),
            ]);

            // 部署尚未执行插件迁移时，仍可发送邮件；日志会提示补齐迁移。
            return $this->selectPaymentInfoProfile($profiles, $strategy, $config, $order->number);
        }
    }

    /**
     * 选择支付信息片段。非数据库上下文用于预览、测试和迁移异常后的保守回退。
     */
    public function selectPaymentInfoProfile(
        array $profiles,
        string $strategy,
        array $config,
        mixed $orderNumber = null,
        bool $deferRoundRobin = false
    ): ?array {
        if ($profiles === []) {
            return null;
        }

        if ($strategy !== 'round_robin') {
            $selectedId = trim((string) ($config['selected_profile'] ?? ''));

            return collect($profiles)->firstWhere('id', $selectedId) ?: $profiles[0];
        }

        if ($deferRoundRobin) {
            return $profiles[0];
        }

        $orderKey = trim((string) $orderNumber);
        $index    = abs(crc32($orderKey)) % count($profiles);

        return $profiles[$index];
    }

    /** 每个支付信息片段连续分配的订单数，默认一次后切换。 */
    public function rotationBatchSize(array $config): int
    {
        $batchSize = filter_var($config['rotation_batch_size'] ?? 1, FILTER_VALIDATE_INT);

        return $batchSize === false ? 1 : max(1, min(1_000_000, $batchSize));
    }

    /** 根据已分配次数计算当前片段位置。 */
    public function rotationProfileIndex(int $assignedCount, int $batchSize, int $profileCount): int
    {
        if ($profileCount < 1) {
            return 0;
        }

        return intdiv(max(0, $assignedCount), max(1, $batchSize)) % $profileCount;
    }

    /**
     * 统一解析登录用户和访客订单的姓名。
     *
     * 访客结账的 Full name 保存在 shipping_customer_name，customer_name 可能是空字符串。
     */
    public function customerName(mixed $order): string
    {
        $names = [
            $order->customer_name          ?? null,
            $order->shipping_customer_name ?? null,
            $order->payment_customer_name  ?? null,
            $order->customer?->name        ?? null,
        ];

        foreach ($names as $name) {
            $name = trim((string) ($name ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * 生成可直接进入订单支付页的链接。
     *
     * 游客订单沿用核心控制器已有的邮箱授权机制；已登录客户仅通过自身会话访问订单。
     */
    private function paymentUrl(Order $order): string
    {
        $params = ['number' => (string) $order->number];
        if (! $order->customer_id && filter_var($order->email, FILTER_VALIDATE_EMAIL)) {
            $params['email'] = (string) $order->email;
        }

        return shop_route('orders.pay', $params);
    }

    /** 生成原生订单邮件使用的订单详情地址，兼容登录和访客订单。 */
    private function orderViewUrl(Order $order): string
    {
        return shop_route('orders.show', [
            'number' => (string) $order->number,
            'email'  => (string) $order->email,
        ]);
    }

    /** 生成与原生订单邮件一致的 View Order 按钮。 */
    private function viewOrderButtonHtml(Order $order): string
    {
        return '<p style="font-size:14px;color:#333;line-height:24px;margin:6px 0 0;word-wrap:break-word;word-break:break-all;">'
            . '<a href="' . $this->escapeHtml($this->orderViewUrl($order)) . '" title="" style="font-size:16px;line-height:45px;display:block;background-color:#fd560f;color:#fff;text-align:center;text-decoration:none;margin-top:20px;border-radius:3px;">'
            . 'View Order'
            . '</a></p>';
    }

    /** 生成与原生订单邮件一致的商品明细表格。 */
    private function orderProductsHtml(Order $order): string
    {
        $rows = '';
        foreach ($order->orderProducts as $product) {
            $image = '';

            try {
                $imageUrl = image_origin($product->image);
                if ($imageUrl) {
                    $image = '<img style="width:60px;height:60px;" src="' . $this->escapeHtml($imageUrl) . '" alt="">';
                }
            } catch (\Throwable) {
                // 商品图片缺失时保留商品行，不影响邮件发送。
            }

            $rows .= '<tr>'
                . '<td style="border:1px solid #eee;padding:4px;text-align:center">' . $image . '</td>'
                . '<td style="font-size:12px;border:1px solid #eee;width:50%;padding:4px;">' . $this->escapeHtml($product->name) . '</td>'
                . '<td style="border:1px solid #eee;padding:4px;font-size:13px;">' . $this->escapeHtml($product->quantity) . '</td>'
                . '<td style="border:1px solid #eee;padding:4px;font-size:13px;">'
                . $this->escapeHtml(currency_format($product->price, $order->currency_code, $order->currency_value))
                . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            return '';
        }

        return '<table style="width:100%;font-weight:300;margin-top:10px;margin-bottom:10px;border-collapse:collapse;">'
            . '<thead><tr>'
            . '<td style="font-size:13px;border:1px solid #eee;background-color:#f8f9fa;padding:7px 4px;width:80px;text-align:center">Image</td>'
            . '<td style="font-size:13px;border:1px solid #eee;background-color:#f8f9fa;padding:7px 4px">Product</td>'
            . '<td style="font-size:13px;border:1px solid #eee;background-color:#f8f9fa;padding:7px 4px">Quantity</td>'
            . '<td style="font-size:13px;border:1px solid #eee;background-color:#f8f9fa;padding:7px 4px">Price</td>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /** 生成与原生订单邮件一致的金额明细表格。 */
    private function orderTotalsHtml(Order $order): string
    {
        $rows = '';
        foreach ($order->orderTotals as $total) {
            $rows .= '<tr>'
                . '<td style="border:1px solid #eee;padding:7px;background-color:#f8f9fa;font-size:13px;width:30%;">'
                . $this->escapeHtml($total->title)
                . '</td>'
                . '<td style="border:1px solid #eee;padding:7px;font-size:13px;"><strong>'
                . $this->escapeHtml(currency_format($total->value, $order->currency_code, $order->currency_value))
                . '</strong></td>'
                . '</tr>';
        }

        if ($rows === '') {
            return '';
        }

        return '<table style="width:100%;font-weight:300;margin-top:10px;margin-bottom:10px;border-collapse:collapse;border:1px solid #eee;">'
            . '<tbody>' . $rows . '</tbody></table>';
    }

    /** 生成收货地址片段；字段先转义，再由模板作为受控 HTML 插入。 */
    private function shippingAddressHtml(Order $order): string
    {
        $address = implode(' ', array_filter([
            $order->shipping_address_1,
            $order->shipping_address_2,
            $order->shipping_city,
            $order->shipping_zone,
            $order->shipping_country,
        ], fn ($value): bool => trim((string) $value) !== ''));

        $lines = [];
        if (trim((string) $order->shipping_customer_name) !== '') {
            $lines[] = 'Name: ' . $this->escapeHtml($order->shipping_customer_name);
        }
        if ($address !== '') {
            $lines[] = 'Address: ' . $this->escapeHtml($address);
        }
        if (trim((string) $order->shipping_zipcode) !== '') {
            $lines[] = 'Postal code: ' . $this->escapeHtml($order->shipping_zipcode);
        }

        return implode('<br>', $lines);
    }

    private function escapeHtml(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 收件人只来自客户邮箱、基础设置中的管理员邮箱和插件公共其他收件人。
     *
     * 模板仅决定是否发送给客户；管理员邮箱始终接收，避免场景配置遗漏后台通知。
     */
    public function recipients(array $settings, array $template, mixed $payload, string $adminEmail = null): array
    {
        $emails  = [$adminEmail ?? system_setting('base.email', '')];
        $targets = (array) ($template['recipients'] ?? []);
        if (in_array('customer', $targets, true)) {
            $emails[] = $payload->email ?? '';
        }
        foreach (preg_split('/[,;\s]+/', (string) ($settings['additional_recipients'] ?? '')) ?: [] as $email) {
            $emails[] = $email;
        }

        return array_values(array_unique(array_filter($emails, fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))));
    }

    private function sanitizeHtml(string $html): string
    {
        $html = preg_replace('/<\s*script\b[^>]*>.*?<\s*\/\s*script\s*>/is', '', $html)  ?? '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*(?:(["\']).*?\1|[^\s>]+)/i', '', $html) ?? $html;

        return preg_replace('/(href|src)\s*=\s*(?:(["\'])\s*javascript:[^"\']*\2|javascript:[^\s>]+)/i', '$1="#"', $html) ?? $html;
    }

    private function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $value;
    }
}
