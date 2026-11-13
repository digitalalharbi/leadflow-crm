@extends('emails.layout', ['preheader' => $digest['title'] ?? null])

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
                            @php $tile = $stat['link'] ?? null; @endphp
                            @if($tile)<a href="{{ rtrim($link, '/') . $tile }}" style="display:block;text-decoration:none;color:inherit;">@endif
                            <div style="background:{{ ($stat['tone'] ?? null) === 'bad' ? '#FDE2E2' : '#F6F3EE' }};border:1px solid {{ ($stat['tone'] ?? null) === 'bad' ? '#F5C2C2' : '#E2DACB' }};border-radius:12px;padding:12px;">
                                <div style="font-size:12px;color:#6b7280;">{{ $stat['label'] }}</div>
                                <div style="font-size:22px;font-weight:800;margin-top:2px;{{ ($stat['tone'] ?? null) === 'bad' ? 'color:#b91c1c;' : '' }}">{{ $stat['value'] ?? '—' }}</div>
                                @if(isset($stat['change']))
                                    @php $good = ($stat['cost'] ?? false) ? $stat['change'] <= 0 : $stat['change'] >= 0; @endphp
                                    <div style="font-size:12px;margin-top:2px;direction:ltr;text-align:right;color:{{ $good ? '#1D5E52' : '#b91c1c' }};">{{ $stat['change'] >= 0 ? '+' : '' }}{{ $stat['change'] }}%</div>
                                @endif
                            </div>
                            @if($tile)</a>@endif
                        </td>
                    @endforeach
                    @if(count($row) === 1)<td width="50%"></td>@endif
                </tr>
            @endforeach
        </table>
    @endif

    @foreach($digest['lists'] ?? [] as $list)
        <p style="margin:18px 0 8px;font-size:14px;font-weight:800;color:#0C2A25;">{{ $list['title'] }}</p>
        @foreach($list['items'] as $i => $item)
            @php($to = $list['links'][$i] ?? null)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 6px;background:#fafaf7;border-radius:8px;border-{{ ($locale ?? 'ar') === 'en' ? 'left' : 'right' }}:4px solid {{ $i === 0 ? '#D9A441' : '#1D5E52' }};">
                <tr>
                    <td style="padding:10px 12px;font-size:14px;line-height:1.7;">@if($to)<a href="{{ rtrim($link, '/') . $to }}" style="color:#1D5E52;text-decoration:none;font-weight:700;">{{ $item }}</a>@else{{ $item }}@endif</td>
                </tr>
            </table>
        @endforeach
    @endforeach

    @if(!empty($rollup))
        <h2 style="margin:18px 0 6px;font-size:15px;">تنبيهات اخترت أن تصلك هنا</h2>
        <ul style="margin:0;padding:0 18px 0 0;font-size:14px;line-height:1.9;">
            @foreach($rollup as $item)<li><strong>{{ $item['title'] }}</strong> — {{ $item['body'] }}</li>@endforeach
        </ul>
    @endif

    <p style="margin:20px 0 0;"><a href="{{ $link }}" style="display:inline-block;background:#1D5E52;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:999px;font-size:14px;font-weight:700;">افتح مهتم</a></p>
@endsection

@section('footer')
    تصلك هذه الرسالة لأنك اشتركت في الملخصات. يمكنك تغيير ذلك من «الملف الشخصي ← التنبيهات».
@endsection
