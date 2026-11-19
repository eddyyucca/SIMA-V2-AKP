@php
    $dep = app(\App\Services\DepreciationService::class);
    $asOf = now();
    $p = $dep->profile($a);
    $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $status = $dep->statusLabel($a, $asOf);
    $acc = $dep->accumulated($a, $asOf);
    $book = $dep->bookValue($a, $asOf);
    $pct = $p['base'] > 0 ? min(100, round($acc / $p['base'] * 100)) : 0;
    $schedule = $dep->schedule($a);
@endphp
<div style="{{ $card }};border-left:4px solid #0ea5e9">
    <h4 style="font-weight:700;margin-bottom:8px">Penyusutan &amp; Nilai Buku <span style="font-weight:500;color:#888;font-size:13px">— {{ $status }}</span></h4>
    @if(! $p['depreciable'])
        <p style="font-size:14px;color:#888">
            @if($p['reason'] === 'rental') Aset sewa tidak disusutkan (bukan milik); biaya sewa dihitung per bulan.
            @elseif($p['reason'] === 'expensed') Harga perolehan {{ $rp($p['cost']) }} di bawah ambang {{ $rp(config('depreciation.threshold')) }}, jadi langsung dibebankan.
            @else Isi harga beli dan tanggal beli agar penyusutan dapat dihitung. @endif
        </p>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:10px">
            <div><div style="color:#888;font-size:12px">Harga perolehan</div><b>{{ $rp($p['cost']) }}</b></div>
            <div><div style="color:#888;font-size:12px">Umur ekonomis</div><b>{{ $p['years'] }} tahun</b></div>
            <div><div style="color:#888;font-size:12px">Nilai residu</div><b>{{ $rp($p['residual']) }} ({{ rtrim(rtrim(number_format($p['residual_percent'], 2), '0'), '.') }}%)</b></div>
            <div><div style="color:#888;font-size:12px">Penyusutan / bulan</div><b>{{ $rp($p['per_month']) }}</b></div>
            <div><div style="color:#888;font-size:12px">Akumulasi saat ini</div><b>{{ $rp($acc) }}</b></div>
            <div><div style="color:#888;font-size:12px">Nilai buku saat ini</div><b style="color:#0284c7;font-size:1.15rem">{{ $rp($book) }}</b></div>
        </div>
        <div style="height:10px;border-radius:99px;background:rgba(127,127,127,.2);overflow:hidden;margin-bottom:12px"><span style="display:block;height:100%;width:{{ $pct }}%;background:linear-gradient(90deg,#0ea5e9,#6366f1)"></span></div>
        <div style="overflow-x:auto">
            <table style="width:100%;font-size:13px;min-width:520px">
                <thead><tr style="text-align:left;color:#888"><th>Tahun</th><th style="text-align:right">Bulan</th><th style="text-align:right">Penyusutan</th><th style="text-align:right">Akumulasi (31 Des)</th><th style="text-align:right">Nilai buku (31 Des)</th></tr></thead>
                <tbody>
                @foreach($schedule as $r)
                    <tr style="border-top:1px solid rgba(127,127,127,.2);{{ $r['year'] === now()->year ? 'font-weight:700' : '' }}">
                        <td>{{ $r['year'] }}</td><td style="text-align:right">{{ $r['months'] }}</td><td style="text-align:right">{{ $rp($r['expense']) }}</td>
                        <td style="text-align:right">{{ $rp($r['accumulated']) }}</td><td style="text-align:right">{{ $rp($r['book_value']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if($a->retired_at)
            @php $bv = $dep->bookValueAtRetirement($a); @endphp
            <p style="font-size:13px;margin-top:8px;color:#888">Penyusutan berhenti {{ $a->retired_at->format('d/m/Y') }} ({{ $a->status === 'lost' ? 'hilang' : 'dilepas' }}). Nilai buku saat itu: <b>{{ $rp($bv) }}</b>.</p>
        @endif
    @endif
</div>
