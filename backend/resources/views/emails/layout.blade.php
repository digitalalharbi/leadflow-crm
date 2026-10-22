<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? '' }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Tahoma,'IBM Plex Sans Arabic',Arial,sans-serif;color:#111827;direction:rtl;text-align:right;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;">
                <tr>
                    <td style="background:{{ $accent ?? '#1D5E52' }};padding:14px 20px;color:#ffffff;font-size:15px;font-weight:700;">
                        مهتم @if(!empty($workspace)) · {{ $workspace }} @endif
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
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
