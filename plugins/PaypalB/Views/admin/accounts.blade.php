@extends('admin::layouts.master')

@section('title', 'PayPal B 收款账号与交易')
@section('content-area-class', 'w-max-1400')
@section('page-title-back', admin_route('plugins.index'))

@section('content')
  @if (session('success'))
    <x-admin-alert type="success" :msg="session('success')" class="mb-4"/>
  @endif
  @if ($errors->any())
    <x-admin-alert type="danger" :msg="$errors->first()" class="mb-4"/>
  @endif

  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title mb-0">添加收款账号</h6></div>
    <div class="card-body">
      <form method="POST" action="{{ admin_route('paypal_b.accounts.store') }}">
        @csrf
        @include('PaypalB::admin.account_fields', ['account' => null, 'submitLabel' => '添加账号'])
      </form>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h6 class="card-title mb-0">自动收款账号池</h6>
      <span class="text-secondary small">企业与个人卖家 API 账号按轮询顺序分配新支付会话。</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th>账号</th><th>类型</th><th>能力</th><th>币种</th><th>状态</th><th>失败冷却</th><th class="text-end">操作</th></tr></thead>
          <tbody>
          @forelse($accounts as $account)
            <tr>
              <td><strong>{{ $account->name }}</strong><div class="small text-secondary">轮询顺序 {{ $account->priority }}</div></td>
              <td>{{ $account->account_type === 'business_api' ? '企业 API' : ($account->account_type === 'personal_seller_api' ? '个人卖家 API' : '历史停用类型') }}</td>
              <td>{{ $account->wallet_enabled ? '钱包' : '' }}{{ $account->wallet_enabled && $account->card_enabled ? ' / ' : '' }}{{ $account->card_enabled ? '信用卡' : '' }}</td>
              <td><code>{{ $account->supported_currencies }}</code></td>
              <td>
                {!! $account->active ? '<span class="text-success">启用</span>' : '<span class="text-secondary">停用</span>' !!}
                @if($account->usesApi())
                  @if($webhook_base_url)
                    <div class="small text-secondary text-break">Webhook: <code>{{ $webhook_base_url }}{{ $account->id }}</code></div>
                  @else
                    <div class="small text-warning">请先在插件配置中保存 B 站公网地址，再复制 Webhook 地址。</div>
                  @endif
                @endif
              </td>
              <td>{{ $account->failure_cooldown_until?->format('Y-m-d H:i:s') ?: '-' }}</td>
              <td class="text-end">
                <details class="d-inline-block text-start">
                  <summary class="btn btn-sm btn-outline-primary">编辑</summary>
                  <form method="POST" action="{{ admin_route('paypal_b.accounts.update', ['account' => $account]) }}" class="border rounded p-3 mt-2 bg-light" style="min-width: 420px">
                    @csrf @method('put')
                    @include('PaypalB::admin.account_fields', ['account' => $account, 'submitLabel' => '保存账号'])
                  </form>
                </details>
                <form method="POST" action="{{ admin_route('paypal_b.accounts.destroy', ['account' => $account]) }}" class="d-inline" onsubmit="return confirm('确定删除该账号吗？')">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger" type="submit">删除</button></form>
              </td>
            </tr>
          @empty
            <tr><td colspan="7" class="text-center text-secondary py-4">还没有配置收款账号。</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h6 class="card-title mb-0">跨站支付会话</h6></div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th>引用 / 订单</th><th>真实商品 / 履约</th><th>金额</th><th>账号</th><th>状态 / 退款</th><th>回调</th><th class="text-end">操作</th></tr></thead>
          <tbody>
          @forelse($transactions as $transaction)
            <tr>
              <td><code>{{ $transaction->reference }}</code><div class="small text-secondary">{{ $transaction->order_number }}</div></td>
              <td>
                <details>
                  <summary>{{ count((array) $transaction->order_items) }} 个商品，{{ count((array) data_get($transaction->fulfillment_evidence, 'shipments', [])) }} 个运单</summary>
                  @foreach((array) $transaction->order_items as $item)
                    <div class="small">{{ $item['name'] }} / {{ $item['sku'] ?: '-' }} x {{ $item['quantity'] }}</div>
                  @endforeach
                  @foreach((array) data_get($transaction->fulfillment_evidence, 'shipments', []) as $shipment)
                    <div class="small text-secondary">{{ $shipment['carrier_name'] ?: $shipment['carrier_code'] }}：{{ $shipment['tracking_no'] }}</div>
                  @endforeach
                  @if(data_get($transaction->fulfillment_evidence, 'delivery_evidence.delivered_at'))
                    <div class="small text-success">签收：{{ data_get($transaction->fulfillment_evidence, 'delivery_evidence.delivered_at') }}</div>
                  @endif
                </details>
              </td>
              <td>{{ $transaction->amount }} {{ $transaction->currency }}</td>
              <td>{{ $transaction->account?->name ?: '-' }}</td>
              <td>
                <div>{{ $transaction->status }}</div>
                <div class="small text-secondary">退款：{{ $transaction->refund_status ?: 'none' }} / {{ $transaction->refunded_amount ?: '0.0000' }} {{ $transaction->currency }}</div>
                @if($transaction->dispute_status)<div class="small text-danger">争议：{{ $transaction->dispute_status }}</div>@endif
                @if($transaction->refunds->isNotEmpty())
                  <details class="small mt-1">
                    <summary>退款记录（{{ $transaction->refunds->count() }}）</summary>
                    @foreach($transaction->refunds as $refund)
                      <div class="text-break">{{ $refund->status }} / {{ $refund->amount }} {{ $refund->currency }} / {{ $refund->provider_refund_id ?: '-' }}</div>
                      @if($refund->last_error)<div class="text-danger text-break">{{ $refund->last_error }}</div>@endif
                      @if($refund->status === 'reconciling')
                        <form method="POST" action="{{ admin_route('paypal_b.refunds.retry', ['refund' => $refund]) }}" class="mt-1">@csrf<button class="btn btn-sm btn-outline-warning" type="submit">重试对账</button></form>
                      @endif
                    @endforeach
                  </details>
                @endif
              </td>
              <td class="small">
                <div>状态：{{ $transaction->callback_status ?: 'idle' }}</div>
                @if($transaction->callback_next_attempt_at)
                  <div class="text-secondary">下次：{{ $transaction->callback_next_attempt_at->format('Y-m-d H:i:s') }}</div>
                @endif
                @if($transaction->callback_last_error)
                  <div class="text-danger text-break">{{ $transaction->callback_last_error }}</div>
                @endif
              </td>
              <td class="text-end">
                @php($hasActiveRefund = $transaction->refunds->contains(fn ($refund) => in_array($refund->status, ['pending', 'processing', 'reconciling'], true)))
                @if($transaction->status === 'completed' && $transaction->refund_status !== 'completed' && ! $hasActiveRefund)
                  <details class="d-inline-block text-start">
                    <summary class="btn btn-sm btn-outline-danger">发起退款</summary>
                    <form method="POST" action="{{ admin_route('paypal_b.transactions.refunds.store', ['transaction' => $transaction]) }}" class="border rounded p-3 mt-2 bg-light" style="min-width: 360px">
                      @csrf
                      <input class="form-control mb-2" name="amount" value="{{ $transaction->amount }}" placeholder="退款金额" required>
                      <input class="form-control mb-2" name="idempotency_key" placeholder="可选幂等键" maxlength="128">
                      <textarea class="form-control mb-2" name="note_to_payer" rows="2" placeholder="给付款人的退款说明" maxlength="255"></textarea>
                      <button class="btn btn-danger btn-sm" type="submit">提交 PayPal 退款</button>
                    </form>
                  </details>
                @endif
                @if(in_array($transaction->status, ['completed', 'cancelled', 'failed', 'expired'], true))
                  <form method="POST" action="{{ admin_route('paypal_b.transactions.retry_callback', ['transaction' => $transaction]) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary" type="submit">重试回调</button></form>
                @endif
                <details class="d-inline-block text-start">
                  <summary class="btn btn-sm btn-outline-primary">签收证据</summary>
                  <form method="POST" action="{{ admin_route('paypal_b.transactions.fulfillment', ['transaction' => $transaction]) }}" class="border rounded p-3 mt-2 bg-light" style="min-width: 360px">
                    @csrf @method('put')
                    <input class="form-control mb-2" type="datetime-local" name="delivered_at" aria-label="签收时间">
                    <input class="form-control mb-2" name="signed_by" placeholder="签收人" maxlength="255">
                    <input class="form-control mb-2" type="url" name="proof_url" placeholder="签收证明 HTTPS 地址" maxlength="1000">
                    <textarea class="form-control mb-2" name="note" rows="2" placeholder="履约备注" maxlength="2000"></textarea>
                    <button class="btn btn-primary btn-sm" type="submit">保存证据</button>
                  </form>
                </details>
              </td>
            </tr>
          @empty
            <tr><td colspan="7" class="text-center text-secondary py-4">还没有跨站支付会话。</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
      <div class="p-3">{{ $transactions->links('admin::vendor/pagination/bootstrap-4') }}</div>
    </div>
  </div>
@endsection
