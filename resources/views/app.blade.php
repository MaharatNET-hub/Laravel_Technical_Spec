<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Submittal Review Assistant</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('style.css') }}?v={{ filemtime(public_path('style.css')) }}">
<script>window.APP = { base: @json(rtrim(url('/'), '/')), chunk: {{ $chunk }} };</script>
</head>
<body>
<header class="appbar">
  <div class="brand"><span class="logo">SR</span><div><b>Submittal Review</b><small>Assistant · demo</small></div></div>
  <nav class="crumbs" id="crumbs"></nav>
  <div class="user"></div>
</header>

<main id="view"></main>

<div id="toast" class="toast" hidden></div>
<script type="module" src="{{ asset('app.js') }}?v={{ filemtime(public_path('app.js')) }}"></script>
</body>
</html>
