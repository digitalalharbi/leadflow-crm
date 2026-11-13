@extends('emails.layout', [
    'preheader' => $body,
    'pill' => $pill ?? null,
])

@section('content')
    <h1 style="margin:0 0 8px;font-size:20px;line-height:1.5;">{{ $title }}</h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.8;color:#374151;">{{ $body }}</p>

    @if(!empty($facts))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border:1px solid #e5e7eb;border-radius:12px;">
            @foreach($facts as $label => $value)
                <tr>
                    <td style="padding:9px 14px;font-size:13px;color:#6b7280;{{ ! $loop->first ? 'border-top:1px solid #eee;' : '' }}">{{ $label }}</td>
                    <td style="padding:9px 14px;font-size:14px;font-weight:700;text-align:left;{{ ! $loop->first ? 'border-top:1px solid #eee;' : '' }}">{{ $value }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @php($urgent = ($severity ?? '') === 'critical')
    <table role="presentation" cellpadding="0" cellspacing="0"><tr>
        @if(!empty($whatsapp))
            <td style="padding-inline-end:8px;"><a href="{{ $whatsapp }}" style="display:inline-block;background:#25D366;color:#073b1d;text-decoration:none;padding:11px 18px;border-radius:999px;font-size:14px;font-weight:700;">واتساب</a></td>
        @endif
        @if(!empty($call))
            <td style="padding-inline-end:8px;"><a href="{{ $call }}" style="display:inline-block;background:#ffffff;color:#1D5E52;border:1.5px solid #1D5E52;text-decoration:none;padding:9px 18px;border-radius:999px;font-size:14px;font-weight:700;">اتصال</a></td>
        @endif
        <td><a href="{{ $link }}" style="display:inline-block;background:{{ $urgent ? '#b91c1c' : '#1D5E52' }};color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:999px;font-size:14px;font-weight:700;">افتح في مهتم</a></td>
    </tr></table>
@endsection

@section('footer')
    تصلك هذه الرسالة لأنك اخترت التنبيه الفوري لهذا النوع. يمكنك تغيير ذلك من «الملف الشخصي ← التنبيهات».
@endsection
