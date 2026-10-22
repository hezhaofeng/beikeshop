@extends('admin::layouts.master')

@section('title', __('admin/common.customer_carts_index'))

@section('content')
  <style>
    #customerCartProductsModal .customer-cart-product-list {
      display: grid;
      gap: 12px;
    }

    #customerCartProductsModal .customer-cart-product-card {
      display: grid;
      grid-template-columns: minmax(0, 1.25fr) minmax(280px, 1fr);
      gap: 20px;
      padding: 18px;
      border: 1px solid var(--bs-border-color);
      border-radius: 8px;
      background: var(--bs-body-bg);
    }

    #customerCartProductsModal .customer-cart-product-name {
      display: block;
      margin-bottom: 8px;
      font-size: 15px;
      font-weight: 600;
      line-height: 1.5;
      overflow-wrap: anywhere;
    }

    #customerCartProductsModal .customer-cart-product-code {
      color: var(--bs-secondary-color);
      font-size: 13px;
      line-height: 1.7;
      overflow-wrap: anywhere;
    }

    #customerCartProductsModal .customer-cart-product-metrics {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
      align-content: center;
    }

    #customerCartProductsModal .customer-cart-product-metric {
      min-width: 0;
      padding: 10px 12px;
      border-radius: 6px;
      background: var(--bs-tertiary-bg);
    }

    #customerCartProductsModal .customer-cart-product-metric-label {
      display: block;
      margin-bottom: 4px;
      color: var(--bs-secondary-color);
      font-size: 12px;
      white-space: nowrap;
    }

    #customerCartProductsModal .customer-cart-product-metric-value {
      display: block;
      font-size: 14px;
      font-weight: 600;
      overflow-wrap: anywhere;
    }

    @media (max-width: 767.98px) {
      #customerCartProductsModal .customer-cart-product-card {
        grid-template-columns: minmax(0, 1fr);
        gap: 12px;
        padding: 14px;
      }

      #customerCartProductsModal .customer-cart-product-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
  </style>

  <div class="card h-min-600">
    <div class="card-body">
      <form method="get" action="{{ admin_route('customer_carts.index') }}" class="row g-2 align-items-end mb-4">
        <div class="col-12 col-lg-4">
          <label for="keyword" class="form-label">{{ __('admin/common.customer_carts_keyword') }}</label>
          <input id="keyword" name="keyword" type="search" class="form-control form-control-sm"
                 value="{{ $filters['keyword'] ?? '' }}" maxlength="100">
        </div>
        <div class="col-12 col-sm-4 col-lg-2">
          <label for="visitor" class="form-label">{{ __('admin/common.customer_carts_visitor_type') }}</label>
          <select id="visitor" name="visitor" class="form-select form-select-sm">
            <option value="all" @selected($filters['visitor'] === 'all')>{{ __('common.all') }}</option>
            <option value="customer" @selected($filters['visitor'] === 'customer')>{{ __('admin/common.customer_carts_registered') }}</option>
            <option value="guest" @selected($filters['visitor'] === 'guest')>{{ __('admin/common.customer_carts_guest') }}</option>
          </select>
        </div>
        <div class="col-6 col-sm-4 col-lg-2">
          <label for="from" class="form-label">{{ __('admin/common.customer_carts_from') }}</label>
          <input id="from" name="from" type="date" class="form-control form-control-sm" value="{{ $filters['from'] }}">
        </div>
        <div class="col-6 col-sm-4 col-lg-2">
          <label for="to" class="form-label">{{ __('admin/common.customer_carts_to') }}</label>
          <input id="to" name="to" type="date" class="form-control form-control-sm" value="{{ $filters['to'] }}">
        </div>
        <div class="col-12 col-lg-2 d-flex gap-2">
          <button type="submit" class="btn btn-outline-primary btn-sm">{{ __('common.filter') }}</button>
          <a href="{{ admin_route('customer_carts.index') }}" class="btn btn-outline-secondary btn-sm">{{ __('common.reset') }}</a>
        </div>
      </form>

      @if ($groups->count())
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead>
            <tr>
              <th>{{ __('admin/common.customer_carts_last_added') }}</th>
              <th>{{ __('admin/common.customer') }}</th>
              <th>{{ __('admin/common.customer_carts_product_count') }}</th>
              <th>{{ __('admin/common.customer_carts_quantity') }}</th>
              <th>{{ __('admin/common.customer_carts_add_count') }}</th>
              <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($groups as $index => $group)
              <tr>
                <td class="text-nowrap">{{ $group['last_added_at'] }}</td>
                <td>
                  @if ($group['customer_id'] && ! $group['deleted_customer'])
                    <a href="{{ admin_route('customers.edit', [$group['customer_id']]) }}">{{ $group['customer_name'] }}</a>
                    <div class="small text-muted">{{ $group['customer_email'] }}</div>
                    <div class="small text-muted">#{{ $group['customer_id'] }}</div>
                  @elseif ($group['customer_id'])
                    <div>{{ __('admin/common.customer_carts_deleted_customer') }} #{{ $group['customer_id'] }}</div>
                  @else
                    <div>{{ __('admin/common.customer_carts_guest') }}</div>
                    <div class="small text-muted">{{ __('admin/common.customer_carts_visitor_id') }}: {{ substr($group['visitor_key'], 0, 12) }}</div>
                  @endif
                </td>
                <td>{{ $group['product_count'] }}</td>
                <td>{{ $group['total_quantity'] }}</td>
                <td>{{ $group['add_count'] }}</td>
                <td class="text-end">
                  <button type="button" class="btn btn-outline-primary btn-sm js-customer-cart-products" data-group-index="{{ $index }}" data-bs-toggle="modal" data-bs-target="#customerCartProductsModal">
                    {{ __('admin/common.customer_carts_view_products') }}
                  </button>
                </td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
        {{ $groups->links('admin::vendor/pagination/bootstrap-4') }}
      @else
        <x-admin-no-data/>
      @endif
    </div>
  </div>

  <div class="modal fade" id="customerCartProductsModal" tabindex="-1" aria-labelledby="customerCartProductsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="customerCartProductsModalLabel">{{ __('admin/common.customer_carts_product_details') }}</h5>
            <div class="small text-muted mt-1" id="customerCartProductsIdentity"></div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('admin/common.customer_carts_close') }}"></button>
        </div>
        <div class="modal-body">
          <div class="customer-cart-product-list" id="customerCartProductsBody"></div>
          <div class="text-center text-muted py-5 d-none" id="customerCartProductsEmpty">
            {{ __('admin/common.customer_carts_no_products') }}
          </div>
        </div>
      </div>
    </div>
  </div>
@endsection

@push('footer')
  <script>
    (() => {
      const groups = @json($groups->items());
      const labels = {
        product: @json(__('admin/common.customer_carts_product')),
        productId: @json(__('admin/common.customer_carts_product_id')),
        productCode: @json(__('admin/common.customer_carts_sku')),
        totalQuantity: @json(__('admin/common.customer_carts_total_quantity')),
        addCount: @json(__('admin/common.customer_carts_add_count')),
        lastAdded: @json(__('admin/common.customer_carts_last_added')),
        registeredCustomer: @json(__('admin/common.customer_carts_registered')),
        deletedCustomer: @json(__('admin/common.customer_carts_deleted_customer')),
        guest: @json(__('admin/common.customer_carts_guest')),
        visitorId: @json(__('admin/common.customer_carts_visitor_id')),
        noDetail: @json(__('admin/common.customer_carts_no_detail')),
      };
      const body = document.getElementById('customerCartProductsBody');
      const emptyState = document.getElementById('customerCartProductsEmpty');
      const identity = document.getElementById('customerCartProductsIdentity');

      const addMetric = (container, label, value) => {
        const metric = document.createElement('div');
        metric.className = 'customer-cart-product-metric';

        const metricLabel = document.createElement('span');
        metricLabel.className = 'customer-cart-product-metric-label';
        metricLabel.textContent = label;

        const metricValue = document.createElement('span');
        metricValue.className = 'customer-cart-product-metric-value';
        metricValue.textContent = value;

        metric.append(metricLabel, metricValue);
        container.append(metric);
      };

      document.querySelectorAll('.js-customer-cart-products').forEach(button => {
        button.addEventListener('click', () => {
          const group = groups[Number(button.dataset.groupIndex)];
          body.replaceChildren();
          identity.textContent = group?.customer_id
            ? `${group.deleted_customer ? labels.deletedCustomer : labels.registeredCustomer} #${group.customer_id}${group.customer_name ? ` · ${group.customer_name}` : ''}${group.customer_email ? ` · ${group.customer_email}` : ''}`
            : `${labels.guest} · ${labels.visitorId}: ${(group?.visitor_key || '').slice(0, 12)}`;

          (group?.products || []).forEach(product => {
            const card = document.createElement('article');
            card.className = 'customer-cart-product-card';
            const details = document.createElement('div');
            const name = product.product_name || labels.product;
            const code = document.createElement('div');
            code.className = 'customer-cart-product-code';

            if (product.url) {
              const link = document.createElement('a');
              link.className = 'customer-cart-product-name';
              link.href = product.url;
              link.target = '_blank';
              link.rel = 'noopener noreferrer';
              link.textContent = `${name} ↗`;
              details.append(link);
            } else {
              const productName = document.createElement('div');
              productName.className = 'customer-cart-product-name';
              productName.textContent = name;
              details.append(productName);
              const unavailable = document.createElement('div');
              unavailable.className = 'customer-cart-product-code';
              unavailable.textContent = labels.noDetail;
              details.append(unavailable);
            }

            const productCode = [product.product_sku, product.sku_model].filter(Boolean).join(' / ');
            code.textContent = `${labels.productCode}: ${productCode || '—'} · ${labels.productId}: #${product.product_id}`;
            details.append(code);

            const metrics = document.createElement('div');
            metrics.className = 'customer-cart-product-metrics';
            addMetric(metrics, labels.totalQuantity, product.quantity);
            addMetric(metrics, labels.addCount, product.add_count);
            addMetric(metrics, labels.lastAdded, product.last_added_at);
            card.append(details, metrics);
            body.append(card);
          });

          emptyState.classList.toggle('d-none', Boolean(group?.products?.length));
        });
      });
    })();
  </script>
@endpush
