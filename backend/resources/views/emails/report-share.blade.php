@extends('emails.layout')

@section('content')
    <h1 style="margin:0 0 10px;font-size:19px;line-height:1.5;">{{ $title }}</h1>
    @if(!empty($message))
        <p style="margin:0 0 14px;font-size:15px;line-height:1.8;white-space:pre-line;">{{ $message }}</p>
    @endif
    <p style="margin:0 0 18px;font-size:15px;line-height:1.8;">
        {{ $intro }}
        @if(!empty($period)) <span style="color:#6b7280;">({{ $period }})</span> @endif
    </p>
    <a href="{{ $link }}" style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:999px;font-size:14px;font-weight:700;">{{ $button }}</a>
    @if(!empty($protected))
        <p style="margin:16px 0 0;font-size:13px;color:#6b7280;">{{ $protectedNote }}</p>
    @endif
@endsection

@section('footer')
    {{ $footer }}
@endsection
