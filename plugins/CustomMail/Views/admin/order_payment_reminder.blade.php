@if (($order->status ?? '') === \Beike\Services\StateMachineService::UNPAID)
  @php
    $reminderSettings = app(\Plugin\CustomMail\Services\CustomMailService::class)->settings();
    $reminderService = app(\Plugin\CustomMail\Services\CustomMailService::class);
    $reminderTemplate = $reminderService->paymentReminderTemplate($reminderSettings, $order);
    $reminderEnabled = $reminderService->enabled($reminderSettings, \Plugin\CustomMail\Services\CustomMailService::PAYMENT_REMINDER_EVENT);
  @endphp
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between gap-3">
      <h6 class="card-title mb-0">自定义邮件</h6>
      <span class="badge {{ $reminderEnabled ? 'bg-success' : 'bg-secondary' }}">{{ $reminderEnabled ? '催款模板已启用' : '催款模板未启用' }}</span>
    </div>
    <div class="card-body">
      <div class="text-secondary mb-3">该订单当前待支付，可手动发送一次待支付催款邮件。邮件中的 <code>@{{payment_url}}</code> 会根据订单原支付方式进入对应支付页。</div>
      @if (! $reminderEnabled)
        <a class="btn btn-outline-primary" href="{{ admin_route('plugins.edit', ['code' => 'custom_mail']) }}">前往配置催款模板</a>
      @elseif (empty($reminderTemplate['subject']) || empty($reminderTemplate['body']))
        <a class="btn btn-outline-primary" href="{{ admin_route('plugins.edit', ['code' => 'custom_mail']) }}">完善催款模板</a>
      @else
        <form method="POST" action="{{ admin_route('custom-mail.orders.payment-reminder', ['order' => $order]) }}" class="d-inline" onsubmit="return confirm('确定向该订单收件人发送催款邮件吗？');">
          @csrf
          <button type="submit" class="btn btn-primary">发送催款邮件</button>
        </form>
        <span class="text-secondary small ms-2">收件人取自催款模板中的客户、管理员和其他收件人设置。</span>
      @endif
    </div>
  </div>
@endif
