@extends('emails.layout')

@section('content')
    <h1 style="margin:0 0 10px;font-size:19px;line-height:1.5;">{{ $title }}</h1>
    <p style="margin:0 0 18px;font-size:15px;line-height:1.8;">{{ $body }}</p>
    <a href="{{ $link }}" style="display:inline-block;background:#1D5E52;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:999px;font-size:14px;font-weight:700;">{{ $button }}</a>
    <p style="margin:16px 0 0;font-size:13px;color:#6b7280;">{{ $note }}</p>
@endsection

@section('footer')
    {{ $footer }}
@endsection
