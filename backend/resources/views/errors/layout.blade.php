<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title') — مهتم</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<style>
  :root { --ink:#0C2A25; --muted:#5b6b67; --brand:#1D5E52; --bg:#F6F3EE; --card:#ffffff; }
  @media (prefers-color-scheme: dark) { :root { --ink:#F6F3EE; --muted:#a9b6b2; --bg:#0C2A25; --card:#123a33; } }
  * { box-sizing: border-box; }
  body { margin:0; min-height:100vh; display:grid; place-items:center; background:var(--bg); color:var(--ink); font-family:"IBM Plex Sans Arabic", system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; padding:1rem; }
  main { max-width: 28rem; width:100%; text-align:center; }
  .card { background:var(--card); border-radius:1.25rem; padding:2rem 1.5rem; box-shadow:0 1px 3px rgba(0,0,0,.08); }
  .code { font-size:.9375rem; color:var(--muted); letter-spacing:.05em; }
  h1 { font-size:1.375rem; margin:.5rem 0; }
  p { color:var(--muted); line-height:1.7; margin:.25rem 0 0; }
  a.home { display:inline-block; margin-top:1.25rem; background:var(--brand); color:#fff; text-decoration:none; padding:.7rem 1.4rem; border-radius:999px; font-weight:600; }
  .contact { margin-top:1rem; font-size:.875rem; color:#6b7280; } .contact a { color:var(--brand); }
  .brand { font-weight:700; font-size:1.25rem; margin-bottom:1rem; display:flex; gap:.5rem; justify-content:center; align-items:center; }
  .brand small { font-weight:500; color:var(--muted); font-size:.875rem; }
</style>
</head>
<body>
<main>
  <div class="brand" aria-label="مهتم">مهتم <small>— حوّل الاهتمام إلى فرصة</small></div>
  <div class="card">
    <div class="code">@yield('code')</div>
    <h1>@yield('title')</h1>
    <p>@yield('message')</p>
    <a class="home" href="https://{{ \App\Support\Brand::DOMAIN }}/">العودة إلى مهتم</a>
  </div>
  <p class="contact">للمساعدة: <a href="mailto:{{ \App\Support\Brand::EMAIL }}" dir="ltr">{{ \App\Support\Brand::EMAIL }}</a></p>
</main>
</body>
</html>
