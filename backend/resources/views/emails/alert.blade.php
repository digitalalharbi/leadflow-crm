@extends('emails.layout', ['accent' => ($severity ?? '') === 'critical' ? '#b91c1c' : (($severity ?? '') === 'warning' ? '#b45309' : '#1D5E52')])

@section('content')
    <h1 style="margin:0 0 10px;font-size:19px;line-height:1.5;">{{ $title }}</h1>
    <p style="margin:0 0 18px;font-size:15px;line-height:1.8;">{{ $body }}</p>
    <a href="{{ $link }}" style="display:inline-block;background:#1D5E52;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:999px;font-size:14px;font-weight:700;">افتح في مهتم</a>
@endsection

@section('footer')
    تصلك هذه الرسالة لأنك اخترت التنبيه الفوري لهذا النوع. يمكنك تغيير ذلك من «الملف الشخصي ← التنبيهات».
@endsection
