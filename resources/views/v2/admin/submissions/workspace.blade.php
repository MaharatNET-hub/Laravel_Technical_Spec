<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Analysis · {{ $s->code }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('style.css') }}?v={{ filemtime(public_path('style.css')) }}">
<script>
window.APP = {
  base: @json(url('v2/admin/submissions/' . $s->id)),
  chunk: 1048576,
  csrf: @json(csrf_token()),
  back: @json(route('v2.admin.submissions.show', $s)),
  code: @json($s->code),
  sub: { file: @json($s->file_name ?? 'submittal.pdf'), size: @json($s->sizeLabel() . ($s->page_count ? ' · ' . $s->page_count . ' pages' : '')) },
};
</script>
</head>
<body>
<header class="appbar">
  <a class="brand" href="{{ route('v2.admin.dashboard') }}" style="color:inherit;text-decoration:none"><span class="logo">SR</span><div><b>Submittal Review</b><small>Admin · analysis</small></div></a>
  <nav class="crumbs" id="crumbs"></nav>
  <div class="user"></div>
</header>
<main id="view"></main>
<div id="toast" class="toast" hidden></div>
<script type="module" src="{{ asset('lib/v2/workspace.js') }}?v={{ filemtime(public_path('lib/v2/workspace.js')) }}"></script>
</body>
</html>
