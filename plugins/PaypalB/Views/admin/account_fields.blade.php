<div class="row g-3">
  <div class="col-md-4"><label class="form-label">账号名称</label><input class="form-control" name="name" value="{{ old('name', $account?->name) }}" required maxlength="100"></div>
  <div class="col-md-4"><label class="form-label">账号类型</label><select class="form-select" name="account_type" required><option value="business_api" @selected(old('account_type', $account?->account_type ?: 'business_api') === 'business_api')>企业 API 自动收款</option><option value="personal_seller_api" @selected(old('account_type', $account?->account_type) === 'personal_seller_api')>个人卖家 API 自动收款</option></select></div>
  <div class="col-md-4"><label class="form-label">支持币种</label><input class="form-control" name="supported_currencies" value="{{ old('supported_currencies', $account?->supported_currencies ?: '*') }}" placeholder="* 或 USD,EUR" required></div>
  <div class="col-md-6"><label class="form-label">Client ID（API）</label><input class="form-control" name="client_id" value="{{ old('client_id', $account?->client_id) }}" maxlength="255"></div>
  <div class="col-md-6"><label class="form-label">Client Secret（API）</label><input class="form-control" name="client_secret" type="password" value="" placeholder="{{ $account ? '留空表示保留原 Secret' : '' }}" maxlength="1000"></div>
  <div class="col-md-6"><label class="form-label">Webhook ID（API）</label><input class="form-control" name="webhook_id" value="{{ old('webhook_id', $account?->webhook_id) }}" maxlength="255"></div>
  <div class="col-md-3"><label class="form-label">优先级</label><input class="form-control" type="number" name="priority" min="0" value="{{ old('priority', $account?->priority ?? 100) }}" required></div>
  <div class="col-md-3 d-flex align-items-end"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="wallet_enabled" value="1" @checked(old('wallet_enabled', $account?->wallet_enabled ?? true))>钱包收款</label></div>
  <div class="col-md-3 d-flex align-items-end"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="card_enabled" value="1" @checked(old('card_enabled', $account?->card_enabled ?? false))>信用卡收款</label></div>
  <div class="col-md-3 d-flex align-items-end"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="active" value="1" @checked(old('active', $account?->active ?? true))>启用账号</label></div>
  <div class="col-12"><div class="form-text">企业和个人卖家 API 账号都会加入自动收款轮询池。只有 PayPal 已授予 Advanced Card Payments 资格的账号才能勾选信用卡；未填写 Webhook ID 的新增账号会先以停用状态保存。</div></div>
  <div class="col-12"><button class="btn btn-primary" type="submit">{{ $submitLabel }}</button></div>
</div>
