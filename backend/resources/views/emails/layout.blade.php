@php($en = ($locale ?? 'ar') === 'en')
<!doctype html>
<html lang="{{ $en ? 'en' : 'ar' }}" dir="{{ $en ? 'ltr' : 'rtl' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ \App\Support\Brand::subject($title ?? '') }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Tahoma,'IBM Plex Sans Arabic',Arial,sans-serif;color:#111827;direction:{{ $en ? 'ltr' : 'rtl' }};text-align:{{ $en ? 'left' : 'right' }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;">
                <tr>
                    <td style="background:#0C2A25;padding:14px 20px;color:#ffffff;font-size:15px;font-weight:700;">
                        <img src="{{ \App\Support\Brand::emailLogo() }}" alt="مهتم" height="32" style="height:32px;width:auto;display:inline-block;vertical-align:middle;border:0;">
                        @if(!empty($workspace))<span style="margin-inline-start:10px;vertical-align:middle;font-weight:600;color:#E6E0D4;">{{ $workspace }}</span>@endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px;">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="padding:12px 20px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;">
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
