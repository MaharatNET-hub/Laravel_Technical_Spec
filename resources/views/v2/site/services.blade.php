@extends('v2.layouts.site', ['title' => __('v2.services.title')])
@section('content')
<section class="page-head"><div class="wrap"><h1>{{ __('v2.services.title') }}</h1><p>{{ __('v2.services.sub') }}</p></div></section>
<section class="block">
  <div class="wrap grid g3">
    @foreach ($services as $s)<div class="card svc"><div class="ic"><x-v2.icon :name="$s->icon"/></div><h3>{{ $s->t('title') }}</h3><p>{{ $s->t('summary') }}</p></div>@endforeach
  </div>
</section>
@include('v2.partials.cta')
@endsection
