@php $img = $p->imageUrl(); @endphp
<div class="cover {{ $img ? '' : 'gen' }}" style="--c: {{ preg_match('/^#[0-9a-fA-F]{3,8}$/', $p->accent ?? '') ? $p->accent : '#1f4fbf' }}">
  @if ($img)
    <img src="{{ $img }}" alt="" loading="lazy">
  @else
    {{-- generated cover: a building silhouette on a blueprint grid --}}
    <svg viewBox="0 0 200 150" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
      <path d="M20 150V60h40v90M60 150V25h50v125M110 150V80h45v70M155 150v-40h30v40"/>
      @for ($y = 40; $y < 145; $y += 14)<path d="M70 {{ $y }}h8M86 {{ $y }}h8M102 {{ $y }}h4" opacity=".7"/>@endfor
      @for ($y = 72; $y < 145; $y += 14)<path d="M28 {{ $y }}h8M44 {{ $y }}h8" opacity=".6"/>@endfor
      @for ($y = 92; $y < 145; $y += 14)<path d="M118 {{ $y }}h8M134 {{ $y }}h8" opacity=".6"/>@endfor
    </svg>
  @endif
  @if ($p->category)<span class="chip">{{ $p->category }}</span>@endif
</div>
