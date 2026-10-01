@extends('v2.layouts.admin', ['title' => $p->exists ? 'Edit project' : 'Add project'])
@section('crumb')<div class="mute" style="font-size:12.5px"><a href="{{ route('v2.admin.projects.index') }}">Projects</a></div>@endsection
@section('content')
<form class="card pad form" method="post" enctype="multipart/form-data" action="{{ $p->exists ? route('v2.admin.projects.update', $p) : route('v2.admin.projects.store') }}" style="max-width:980px">
  @csrf @if ($p->exists) @method('put') @endif
  <div class="lang-pair">
    <label class="f">Title (English) *<input class="inp" name="title_en" value="{{ old('title_en', $p->title_en) }}" required></label>
    <label class="f">العنوان (عربي) *<input class="inp" name="title_ar" dir="rtl" value="{{ old('title_ar', $p->title_ar) }}" required></label>
    <label class="f">Location<input class="inp" name="location_en" value="{{ old('location_en', $p->location_en) }}"></label>
    <label class="f">الموقع<input class="inp" name="location_ar" dir="rtl" value="{{ old('location_ar', $p->location_ar) }}"></label>
    <label class="f">Summary <small>(card text)</small><textarea class="inp" name="summary_en">{{ old('summary_en', $p->summary_en) }}</textarea></label>
    <label class="f">الملخص<textarea class="inp" name="summary_ar" dir="rtl">{{ old('summary_ar', $p->summary_ar) }}</textarea></label>
    <label class="f">Full description <small>(blank line = new paragraph)</small><textarea class="inp" name="body_en" style="min-height:160px">{{ old('body_en', $p->body_en) }}</textarea></label>
    <label class="f">الوصف الكامل<textarea class="inp" name="body_ar" dir="rtl" style="min-height:160px">{{ old('body_ar', $p->body_ar) }}</textarea></label>
  </div>
  <div class="row2">
    <label class="f">Sector / category<input class="inp" name="category" value="{{ old('category', $p->category) }}" list="cats" placeholder="Residential, Healthcare…"></label>
    <label class="f">Year<input class="inp" type="number" name="year" value="{{ old('year', $p->year) }}"></label>
  </div>
  <datalist id="cats">@foreach (\App\Models\V2\Project::whereNotNull('category')->distinct()->pluck('category') as $c)<option value="{{ $c }}">@endforeach</datalist>
  <div class="row2">
    <label class="f">Web address <small>(blank = from the title)</small><input class="inp" name="slug" value="{{ old('slug', $p->slug) }}" placeholder="residential-towers"></label>
    <label class="f">Order<input class="inp" type="number" name="sort" value="{{ old('sort', $p->sort ?? 0) }}"></label>
  </div>
  <div class="row2">
    <label class="f">Photo <small>(JPG/PNG/WebP, max 4 MB — without one, a drawn cover in the colour below is used)</small><input class="inp" type="file" name="image" accept="image/*">
      @if ($p->imageUrl())<span style="display:flex;gap:10px;align-items:center"><img class="thumb" src="{{ $p->imageUrl() }}" alt=""><label class="check"><input type="checkbox" name="remove_image" value="1"> Remove photo</label></span>@endif</label>
    <label class="f">Cover colour<input class="inp" type="color" name="accent" value="{{ old('accent', $p->accent ?: '#1f4fbf') }}" style="height:42px;padding:4px"></label>
  </div>
  <div style="display:flex;gap:18px;flex-wrap:wrap">
    <label class="check"><input type="checkbox" name="active" value="1" @checked(old('active', $p->active ?? true))> Show on the website</label>
    <label class="check"><input type="checkbox" name="featured" value="1" @checked(old('featured', $p->featured))> Featured on the home page</label>
  </div>
  <div><button class="btn primary">Save project</button> <a class="btn" href="{{ route('v2.admin.projects.index') }}">Cancel</a></div>
</form>
@endsection
