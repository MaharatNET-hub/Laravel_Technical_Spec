<a class="card proj" href="{{ route('v2.project', $p) }}">
  @include('v2.partials.cover', ['p' => $p])
  <div class="body">
    <h3 style="margin:0">{{ $p->t('title') }}</h3>
    <div class="meta"><span>{{ $p->t('location') }}</span>@if ($p->year)<span>· {{ $p->year }}</span>@endif</div>
    <p>{{ \Illuminate\Support\Str::limit($p->t('summary'), 130) }}</p>
  </div>
</a>
