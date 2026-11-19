<!doctype html>
<html lang="id"><head><meta charset="utf-8">
<style>
 @page { margin: 0; size: {{ ($orient ?? 'landscape') === 'landscape' ? $length : $tape }}mm {{ ($orient ?? 'landscape') === 'landscape' ? $tape : $length }}mm; }
 * { margin: 0; padding: 0; }
 body { font-family: Helvetica, Arial, sans-serif; font-weight: bold; color: #000; }
 .page { position: relative; width: {{ $length }}mm; height: {{ $tape }}mm; overflow: hidden; page-break-after: always; }
 .page:last-child { page-break-after: auto; }
 .sheet { position: relative; overflow: hidden; page-break-after: always; }
 .sheet:last-child { page-break-after: auto; }
 .abs { position: absolute; }
 .row { white-space: nowrap; overflow: hidden; }
 .cap { font-weight: bold; }
</style></head><body>
@foreach($labels as $l)
@php $rot = $orient ?? 'landscape'; $port = $rot !== 'landscape'; @endphp
@if($port)<div class="sheet" style="width:{{ $tape }}mm;height:{{ $length }}mm">@endif
@php
    // pusatkan teks secara vertikal: tinggi garis = 1.15 × ukuran huruf, sisanya jadi padding atas
    $rowStyle = function (float $pt, float $h, float $shiftEm = 0.0) {
        $lh = round($pt * 0.3528 * 1.15, 2);
        $top = round(max(0, ($h - $lh) / 2 + $shiftEm * $pt * 0.3528), 2);
        return ['lh' => $lh, 'top' => $top, 'h' => round($h - $top, 2)];
    };
    $r1 = $rowStyle($l['f1'], $G['h1'], $G['shift']);
    $r2 = $rowStyle($l['f2'], $G['h2'], $G['shift']);
    $r3 = $rowStyle($l['f3'], $G['h3'], $G['shift']);
@endphp
<div class="page"@if($port) style="position:absolute;transform-origin:0 0;page-break-after:auto;{{ $rot === 'portrait-ccw' ? 'left:0;top:' . $length . 'mm;transform:rotate(-90deg)' : 'left:' . $tape . 'mm;top:0;transform:rotate(90deg)' }}"@endif>
    {{-- satu bingkai luar; kolom kiri tiga baris, sel QR persegi di kanan dengan garis pemisah tegak --}}
    <div class="abs" style="left:{{ $G['c'] }}mm;top:{{ $G['c'] }}mm;width:{{ $G['iw'] }}mm;height:{{ $G['ih'] }}mm;border:{{ $G['ob'] }}mm solid #000">
        <div class="abs" style="left:0;top:0;width:{{ $G['leftW'] }}mm;height:{{ $G['ih'] }}mm">
            <div class="row" style="height:{{ $r1['h'] }}mm;padding-top:{{ $r1['top'] }}mm;line-height:{{ $r1['lh'] }}mm;border-bottom:{{ $G['bw'] }}mm solid #000;padding-left:{{ $G['pad'] }}mm;font-size:{{ $l['f1'] }}pt">{{ $l['company'] }}</div>
            <div class="row" style="height:{{ $r2['h'] }}mm;padding-top:{{ $r2['top'] }}mm;line-height:{{ $r2['lh'] }}mm;border-bottom:{{ $G['bw'] }}mm solid #000;padding-left:{{ $G['pad'] }}mm;font-size:{{ $l['f2'] }}pt">@if($l['c2cap'])<span class="cap" style="font-size:{{ round($l['f2'] * 0.72, 1) }}pt">{{ $l['c2cap'] }}</span> @endif{{ $l['c2'] }}</div>
            <div class="row" style="height:{{ $r3['h'] }}mm;padding-top:{{ $r3['top'] }}mm;line-height:{{ $r3['lh'] }}mm;padding-left:{{ $G['pad'] }}mm;font-size:{{ $l['f3'] }}pt">@if($l['c3cap'])<span class="cap" style="font-size:{{ round($l['f3'] * 0.72, 1) }}pt">{{ $l['c3cap'] }}</span> @endif{{ $l['c3'] }}</div>
        </div>
        <div class="abs" style="left:{{ $G['leftW'] }}mm;top:0;width:{{ $G['qb'] - $G['bw'] }}mm;height:{{ $G['ih'] }}mm;border-left:{{ $G['bw'] }}mm solid #000">
            <img class="abs" src="{{ $l['qrsvg'] }}" style="left:{{ $G['qpad'] }}mm;top:{{ $G['qpad'] }}mm;width:{{ $G['qb'] - $G['bw'] - 2*$G['qpad'] }}mm;height:{{ $G['qb'] - $G['bw'] - 2*$G['qpad'] }}mm">
        </div>
    </div>
</div>
@if($port)</div>@endif
@endforeach
</body></html>
