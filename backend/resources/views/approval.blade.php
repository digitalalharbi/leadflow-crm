<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<title>مهتم — قرار</title>
<style>
  :root { --ink:#0f1720; --muted:#667085; --line:#e6e8ec; --brand:#0f766e; --danger:#d1435b; --bg:#f3f4f6; }
  @media (prefers-color-scheme: dark) { :root { --ink:#eef1f4; --muted:#9aa3ae; --line:#2a2f36; --bg:#111418; } .card{background:#1a1e24!important} }
  * { box-sizing: border-box; }
  body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; background:var(--bg); color:var(--ink); }
  main { max-width: 28rem; margin: 0 auto; padding: 1.5rem 1rem; }
  .brand { font-weight: 700; font-size: 1.1rem; margin-bottom: 1rem; }
  .card { background:#fff; border-radius: 1.25rem; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
  h1 { font-size: 1.25rem; margin: 0 0 .25rem; }
  .muted { color: var(--muted); font-size: .875rem; }
  .row { margin-top: .75rem; font-size: .9375rem; line-height: 1.6; }
  .warn { color: var(--danger); font-size: .8125rem; margin-top: .5rem; }
  form { display:flex; gap:.5rem; margin-top: 1.25rem; }
  button { flex:1; border:0; border-radius: 999px; padding: .85rem 1rem; font-size: 1rem; font-weight: 600; cursor:pointer; }
  .yes { background: var(--brand); color:#fff; }
  .no { background: transparent; color: var(--ink); border: 1px solid var(--line); }
  .done { font-size: 1.05rem; font-weight: 600; }
</style>
</head>
<body>
<main>
  <div class="brand">مهتم</div>
  <div class="card">
    @if ($result)
      <p class="done">
        @switch($result)
          @case('executed') تمت الموافقة ونُفِّذ التغيير. @break
          @case('approved') تمت الموافقة، والتنفيذ جارٍ. @break
          @case('rejected') رُفض الإجراء. لم يتغير شيء. @break
          @case('blocked') أوقفته قواعد العميل — لم يتغير شيء. @break
          @case('failed') رفضت المنصة التغيير — لم يتغير شيء. @break
          @default تم.
        @endswitch
      </p>
      <p class="muted">{{ $action }} — {{ $campaign }}</p>
    @elseif ($error)
      <p class="done">
        @switch($error)
          @case('used') استُخدم هذا الرابط من قبل. @break
          @case('expired') انتهت صلاحية هذا الرابط. افتح «مهتم» لاتخاذ القرار. @break
          @case('decided') اتُّخذ قرار في هذا الإجراء بالفعل. @break
          @default هذا الرابط غير صالح.
        @endswitch
      </p>
    @else
      <h1>{{ $action }}</h1>
      <p class="muted">{{ $campaign }}</p>
      @isset($payload['daily_budget_minor'])
        <p class="row">الميزانية اليومية الجديدة: <strong dir="ltr">{{ number_format($payload['daily_budget_minor'] / 100, 2) }}</strong></p>
      @endisset
      <p class="row">{{ $reason }}</p>
      @if ($impact)<p class="row muted">↗ {{ $impact }}</p>@endif
      @foreach ($blocks as $block)<p class="warn">⚠ {{ $block }}</p>@endforeach
      <p class="muted" style="margin-top:1rem">القرار باسم {{ $person }} — مرة واحدة.</p>
      <form method="post">
        <button class="yes" type="submit" name="decision" value="approve">موافقة</button>
        <button class="no" type="submit" name="decision" value="reject">رفض</button>
      </form>
    @endif
  </div>
</main>
</body>
</html>
