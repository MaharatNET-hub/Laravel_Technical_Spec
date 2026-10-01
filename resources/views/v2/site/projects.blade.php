@extends('v2.layouts.site', ['title' => __('v2.projects.title')])
@section('content')
<section class="page-head"><div class="wrap"><h1>{{ __('v2.projects.title') }}</h1><p>{{ __('v2.home.projects_sub') }}</p></div></section>
<section class="block">
  <div class="wrap">
    @if ($categories->count() > 1)
      <div class="chips">
        <a href="{{ route('v2.projects') }}" class="{{ $category ? '' : 'on' }}">{{ __('v2.projects.all') }}</a>
        @foreach ($categories as $cat)<a href="{{ route('v2.projects', ['category' => $cat]) }}" class="{{ $category === $cat ? 'on' : '' }}">{{ $cat }}</a>@endforeach
      </div>
    @endif
    <div class="grid g3">@foreach ($projects as $p) @include('v2.partials.project-card') @endforeach</div>
  </div>
</section>
@endsection
