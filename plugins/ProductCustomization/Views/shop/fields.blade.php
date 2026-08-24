@php($fields = $customization['fields'] ?? [])
@if($fields)
  <div class="product-customization mb-md-3">
    <p class="mb-2">{{ __('ProductCustomization::common.title') }}</p>
    @foreach($fields as $field)
      <div class="mb-2">
        <label class="form-label" for="customization-{{ $field['key'] }}">
          {{ $field['label'] }}
          @if($field['required'])<span class="text-danger">*</span>@endif
        </label>
        @if($field['type'] === 'textarea')
          <textarea id="customization-{{ $field['key'] }}" class="form-control" rows="3" maxlength="{{ $field['max_length'] ?: 10000 }}" v-model="customizationValues['{{ $field['key'] }}']" placeholder="{{ $field['placeholder'] }}"></textarea>
        @elseif($field['type'] === 'select')
          <select id="customization-{{ $field['key'] }}" class="form-select" v-model="customizationValues['{{ $field['key'] }}']">
            <option value="">{{ __('ProductCustomization::common.please_choose') }}</option>
            @foreach($field['options'] as $option)
              <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
            @endforeach
          </select>
        @else
          <input id="customization-{{ $field['key'] }}" type="{{ $field['type'] === 'number' ? 'text' : 'text' }}" class="form-control" maxlength="{{ $field['max_length'] ?: 10000 }}" v-model="customizationValues['{{ $field['key'] }}']" placeholder="{{ $field['placeholder'] }}" inputmode="{{ $field['type'] === 'number' ? 'numeric' : 'text' }}">
        @endif
      </div>
    @endforeach
  </div>
@endif
