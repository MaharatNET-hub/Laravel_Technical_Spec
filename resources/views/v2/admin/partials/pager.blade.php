@if ($paginator->hasPages())
<nav class="pager">
  @if ($paginator->onFirstPage())<span class="mute">‹</span>@else<a href="{{ $paginator->previousPageUrl() }}">‹</a>@endif
  @foreach ($elements as $el)
    @if (is_string($el))<span class="mute">{{ $el }}</span>@endif
    @if (is_array($el))@foreach ($el as $page => $url)@if ($page == $paginator->currentPage())<span class="on">{{ $page }}</span>@else<a href="{{ $url }}">{{ $page }}</a>@endif @endforeach @endif
  @endforeach
  @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}">›</a>@else<span class="mute">›</span>@endif
</nav>
@endif
