@extends('emails.layout')

@section('content')
    <h1 style="margin:0 0 4px;font-size:20px;">{{ $digest['title'] }}</h1>
    @if(!empty($digest['period']))
        <p style="margin:0 0 16px;color:#6b7280;font-size:13px;direction:ltr;text-align:right;">{{ $digest['period'] }}</p>
    @endif

    @if(!empty($digest['stats']))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:18px;">
            @foreach(array_chunk($digest['stats'], 2) as $row)
                <tr>
                    @foreach($row as $stat)
                        <td width="50%" style="padding:4px;vertical-align:top;">
                            <div style="background:#f9fafb;border-radius:12px;padding:12px;">
                                <div style="font-size:12px;color:#6b7280;">{{ $stat['label'] }}</div>
                                <div style="font-size:22px;font-weight:800;margin-top:2px;{{ ($stat['tone'] ?? null) === 'bad' ? 'color:#b91c1c;' : '' }}">{{ $stat['value'] ?? '—' }}</div>
                                @if(isset($stat['change']))
                                    @php $good = ($stat['cost'] ?? false) ? $stat['change'] <= 0 : $stat['change'] >= 0; @endphp
                                    <div style="font-size:12px;margin-top:2px;direction:ltr;text-align:right;color:{{ $good ? '#0f766e' : '#b91c1c' }};">{{ $stat['change'] >= 0 ? '+' : '' }}{{ $stat['change'] }}%</div>
                                @endif
                            </div>
                        </td>
                    @endforeach
                    @if(count($row) === 1)<td width="50%"></td>@endif
                </tr>
            @endforeach
        </table>
    @endif

    @foreach($digest['lists'] ?? [] as $list)
        <h2 style="margin:16px 0 6px;font-size:15px;">{{ $list['title'] }}</h2>
        <ul style="margin:0;padding:0 18px 0 0;font-size:14px;line-height:1.9;">
            @foreach($list['items'] as $item)<li>{{ $item }}</li>@endforeach
        </ul>
    @endforeach

    @if(!empty($rollup))
        <h2 style="margin:18px 0 6px;font-size:15px;">تنبيهات اخترت أن تصلك هنا</h2>
        <ul style="margin:0;padding:0 18px 0 0;font-size:14px;line-height:1.9;">
            @foreach($rollup as $item)<li><strong>{{ $item['title'] }}</strong> — {{ $item['body'] }}</li>@endforeach
        </ul>
    @endif

    <p style="margin:20px 0 0;"><a href="{{ $link }}" style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:999px;font-size:14px;font-weight:700;">افتح مهتم</a></p>
@endsection

@section('footer')
    تصلك هذه الرسالة لأنك اشتركت في الملخصات. يمكنك تغيير ذلك من «الملف الشخصي ← التنبيهات».
@endsection
