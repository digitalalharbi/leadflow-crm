@php($en = ($locale ?? 'ar') === 'en')
@php($tones = ['critical' => ['#FDE2E2', '#b91c1c'], 'warning' => ['#FCEBC8', '#9a5b05'], 'good' => ['#D9EEE5', '#1D5E52'], 'neutral' => ['#ECEAE3', '#57534e']])
<!doctype html>
<html lang="{{ $en ? 'en' : 'ar' }}" dir="{{ $en ? 'ltr' : 'rtl' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ \App\Support\Brand::subject($title ?? '') }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Tahoma,'IBM Plex Sans Arabic',Arial,sans-serif;color:#111827;direction:{{ $en ? 'ltr' : 'rtl' }};text-align:{{ $en ? 'left' : 'right' }};">
@if(!empty($preheader))<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#f3f4f6;">{{ $preheader }}</div>@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;">
                <tr>
                    <td style="background:#0C2A25;padding:16px 20px;border-bottom:3px solid #D9A441;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                            <td style="vertical-align:middle;">
                                <img src="{{ \App\Support\Brand::emailLogo() }}" alt="مهتم" height="32" style="height:32px;width:auto;display:inline-block;vertical-align:middle;border:0;">
                            </td>
                            @if(!empty($workspace))<td style="vertical-align:middle;text-align:{{ $en ? 'right' : 'left' }};font-size:13px;font-weight:600;color:#E6E0D4;">{{ $workspace }}</td>@endif
                        </tr></table>
                    </td>
                </tr>
                @if(!empty($testNotice))
                <tr><td style="background:#FFF8E6;border-bottom:2px solid #D9A441;padding:9px 20px;font-size:12px;line-height:1.7;color:#5b4310;">{{ $testNotice }}</td></tr>
                @endif
                <tr>
                    <td style="padding:22px 20px;">
                        @if(!empty($pill))
                            @php([$pillBg, $pillFg] = $tones[$pill['tone'] ?? 'neutral'] ?? $tones['neutral'])
                            <span style="display:inline-block;background:{{ $pillBg }};color:{{ $pillFg }};border-radius:999px;padding:3px 11px;font-size:12px;font-weight:700;margin:0 0 10px;">{{ $pill['text'] }}</span>
                        @endif
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="padding:12px 20px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;line-height:1.8;">
                        @yield('footer')
                        <p style="margin:10px 0 0;color:#9ca3af;font-size:11px;">{{ $en ? 'Mohtam' : 'مهتم — '.\App\Support\Brand::TAGLINE_AR }} · <a href="https://{{ \App\Support\Brand::DOMAIN }}" style="color:#1D5E52;text-decoration:none;">{{ \App\Support\Brand::DOMAIN }}</a> · <a href="mailto:{{ \App\Support\Brand::EMAIL }}" style="color:#1D5E52;text-decoration:none;">{{ \App\Support\Brand::EMAIL }}</a></p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
