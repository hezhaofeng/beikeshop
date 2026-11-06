@extends('layout.master')
@section('body-class', 'page-home')
@section('content')

<div class="modules-box" id="home-modules-box">

  @hook('home.modules.before')

  @foreach($modules as $module)
    @include($module['view_path'], $module)
  @endforeach

  @hook('home.modules.after')

</div>

<div class="icon-whatsapp" role="complementary" aria-label="和客服联系">
  <a
    href="https://wa.me/85259738875?text={{ rawurlencode('Chat From Website https://www.topgearsstore.com/') }}"
    target="_blank"
    rel="noopener noreferrer"
    aria-label="通过 WhatsApp 和客服联系"
  >
    <b>chat</b>
    <img src="https://www.topgearsstore.com/View/2020052701/images/whatsapp.png" alt="WhatsApp">
  </a>
</div>

@endsection

@if ($design)
@push('add-scripts')
<script>
  document.addEventListener('click', function(event) {
    event.preventDefault();
  });
</script>
@endpush
@endif
