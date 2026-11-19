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
</style></head><body>
@foreach($labels as $l)
@php $rot = $orient ?? 'landscape'; $port = $rot !== 'landscape'; @endphp
@if($port)<div class="sheet" style="width:{{ $tape }}mm;height:{{ $length }}mm">@endif
@php
    // pusatkan teks secara vertikal di dalam barisnya
    $rowStyle = function (float $pt, float $h, float $shiftEm) {
        $lh = round($pt * 0.3528 * 1.15, 2);
        $top = round(max(0, ($h - $lh) / 2 + $shiftEm * $pt * 0.3528), 2);
        return ['lh' => $lh, 'top' => $top, 'h' => round($h - $top, 2)];
    };
    $r1 = $rowStyle($l['f1'], $G['rowH'], $G['shift']);
    $r2 = $l['c2'] !== '' ? $rowStyle($l['f2'], $G['rowH'], $G['shift']) : null;
@endphp
<div class="page"@if($port) style="position:absolute;transform-origin:0 0;page-break-after:auto;{{ $rot === 'portrait-ccw' ? 'left:0;top:' . $length . 'mm;transform:rotate(-90deg)' : 'left:' . $tape . 'mm;top:0;transform:rotate(90deg)' }}"@endif>
    @if($G['border'])
        <div class="abs" style="left:{{ $G['inset'] }}mm;top:{{ $G['inset'] }}mm;width:{{ $length - 2*$G['inset'] - 2*$G['bw'] }}mm;height:{{ $tape - 2*$G['inset'] - 2*$G['bw'] }}mm;border:{{ $G['bw'] }}mm solid #000"></div>
    @endif
    <img class="abs" src="{{ $l['qrsvg'] }}" style="left:{{ $G['e'] }}mm;top:{{ $G['e'] }}mm;width:{{ $G['qr'] }}mm;height:{{ $G['qr'] }}mm">
    <div class="abs" style="left:{{ $G['textX'] }}mm;top:{{ $G['e'] }}mm;width:{{ $G['textW'] }}mm;height:{{ $G['qr'] }}mm;overflow:hidden">
        <div class="row" style="height:{{ $r1['h'] }}mm;padding-top:{{ $r1['top'] }}mm;line-height:{{ $r1['lh'] }}mm;font-size:{{ $l['f1'] }}pt">{{ $l['c1'] }}</div>
        @if($r2)
            <div class="row" style="height:{{ $r2['h'] }}mm;padding-top:{{ $r2['top'] }}mm;line-height:{{ $r2['lh'] }}mm;font-size:{{ $l['f2'] }}pt">{{ $l['c2'] }}</div>
        @endif
    </div>
</div>
@if($port)</div>@endif
@endforeach
</body></html>
