<template id="module-editor-latest-products-template">
  <div class="image-edit-wrapper">
    <div class="module-editor-row">{{ __('admin/builder.text_set_up') }}</div>
    <div class="module-edit-group">
      <div class="module-edit-title">{{ __('admin/builder.text_module_title') }}</div>
      <text-i18n v-model="module.title"></text-i18n>
    </div>
    <div class="module-edit-group">
      <div class="module-edit-title">{{ __('Bestseller::common.limit') }}</div>
      <el-input type="number" min="1" max="60" v-model="module.limit" size="small"></el-input>
    </div>
  </div>
</template>

<script type="text/javascript">
Vue.component('module-editor-latest-products', {
  template: '#module-editor-latest-products-template',
  props: ['module'],
  watch: {
    module: {
      handler: function (val) { this.$emit('on-changed', val); },
      deep: true
    }
  }
});
</script>

@push('footer-script')
  <script>
    register = @json($register);
    register.make = {
      style: { background_color: '' },
      title: languagesFill('{{ __('admin/builder.text_module_title') }}'),
      limit: 12,
    };
    app.source.modules.push(register);
  </script>
@endpush
