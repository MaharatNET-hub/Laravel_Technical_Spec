@extends('v2.layouts.admin', ['title' => $s->exists ? 'Edit service' : 'Add service'])
@section('crumb')<div class="mute" style="font-size:12.5px"><a href="{{ route('v2.admin.services.index') }}">Services</a></div>@endsection
@section('content')
<form class="card pad form" method="post" action="{{ $s->exists ? route('v2.admin.services.update', $s) : route('v2.admin.services.store') }}" style="max-width:900px">
  @csrf @if ($s->exists) @method('put') @endif
  <div class="lang-pair">
    <label class="f">Title (English) *<input class="inp" name="title_en" value="{{ old('title_en', $s->title_en) }}" required></label>
    <label class="f">العنوان (عربي) *<input class="inp" name="title_ar" dir="rtl" value="{{ old('title_ar', $s->title_ar) }}" required></label>
    <label class="f">Summary<textarea class="inp" name="summary_en">{{ old('summary_en', $s->summary_en) }}</textarea></label>
    <label class="f">الملخص<textarea class="inp" name="summary_ar" dir="rtl">{{ old('summary_ar', $s->summary_ar) }}</textarea></label>
  </div>
  <div class="f" style="font-weight:600;font-size:13px;color:var(--ink2)">Icon</div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    @foreach ($icons as $ic)<label class="btn" style="cursor:pointer"><input type="radio" name="icon" value="{{ $ic }}" @checked(old('icon', $s->icon) === $ic)><x-v2.icon :name="$ic"/></label>@endforeach
  </div>
  <div class="row2"><label class="f">Order<input class="inp" type="number" name="sort" value="{{ old('sort', $s->sort ?? 0) }}"></label><label class="check" style="align-self:end"><input type="checkbox" name="active" value="1" @checked(old('active', $s->active ?? true))> Show on the website</label></div>
  <div><button class="btn primary">Save service</button> <a class="btn" href="{{ route('v2.admin.services.index') }}">Cancel</a></div>
</form>
@endsection
