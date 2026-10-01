@extends('v2.layouts.site', ['title' => $project->t('title')])
@section('content')
<section class="page-head"><div class="wrap"><div class="crumb"><a href="{{ route('v2.projects') }}">← {{ __('v2.projects.back') }}</a></div><h1>{{ $project->t('title') }}</h1><p>{{ $project->t('summary') }}</p></div></section>
<section class="block">
  <div class="wrap layout2">
    <div>
      <div class="card" style="padding:0;overflow:hidden;margin-bottom:26px">@include('v2.partials.cover', ['p' => $project])</div>
      <div class="prose">@foreach (preg_split('/\n\s*\n/', (string) $project->t('body')) as $para)<p>{{ $para }}</p>@endforeach</div>
    </div>
    <aside class="side">
      <div class="card"><dl class="kv">
        @if ($project->category)<dt>{{ __('v2.projects.category') }}</dt><dd>{{ $project->category }}</dd>@endif
        @if ($project->t('location'))<dt>{{ __('v2.projects.location') }}</dt><dd>{{ $project->t('location') }}</dd>@endif
        @if ($project->year)<dt>{{ __('v2.projects.year') }}</dt><dd>{{ $project->year }}</dd>@endif
      </dl></div>
      <a class="btn primary" href="{{ route('v2.contact') }}">{{ __('v2.nav.contact') }}</a>
    </aside>
  </div>
</section>
@if ($more->count())
<section class="block alt"><div class="wrap"><div class="head"><h2>{{ __('v2.projects.more') }}</h2></div><div class="grid g3">@foreach ($more as $p) @include('v2.partials.project-card') @endforeach</div></div></section>
@endif
@endsection
