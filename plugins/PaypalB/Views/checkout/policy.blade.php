@extends('PaypalB::layout.checkout')

@section('title', $title)

@section('content')
  <h1 class="h4 mb-4">{{ $title }}</h1>

  @foreach($body as $line)
    <p>{{ $line }}</p>
  @endforeach

  <div class="small text-secondary mt-4 pt-3 border-top">
    {{ trans('PaypalB::common.policy_available') }}
  </div>
@endsection
