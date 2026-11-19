<!doctype html>
<html lang="id"><head><meta charset="utf-8">
<style>
 @page { margin: 0; size: {{ $length }}mm {{ $tape }}mm; }
 * { margin: 0; padding: 0; }
 body { font-family: DejaVu Sans, sans-serif; color: #000; }
 .page { width: {{ $length }}mm; height: {{ $tape }}mm; overflow: hidden; page-break-after: always; position: relative; }
 .page:last-child { page-break-after: auto; }
 .qr { position: absolute; left: {{ $L['margin'] }}mm; top: {{ $L['margin'] }}mm; width: {{ $L['qr'] }}mm; height: {{ $L['qr'] }}mm; }
 .txt { position: absolute; left: {{ $L['qr'] + 2 * $L['margin'] }}mm; top: {{ $L['margin'] }}mm; width: {{ $L['textW'] }}mm; height: {{ $L['qr'] }}mm; overflow: hidden; }
 .t { font-size: {{ $L['t'] }}pt; font-weight: bold; line-height: 1.1; white-space: nowrap; }
 .l1 { font-size: {{ $L['l1'] }}pt; line-height: 1.2; white-space: nowrap; }
 .l2 { font-size: {{ $L['l2'] }}pt; line-height: 1.2; white-space: nowrap; }
 .co { font-size: {{ max(4, $L['l2'] - 1) }}pt; letter-spacing: .3pt; color: #000; margin-top: {{ $tape >= 24 ? '1mm' : '0' }}; white-space: nowrap; }
</style></head><body>
@foreach($labels as $l)
<div class="page">
    <img class="qr" src="{{ $l['qrsvg'] }}">
    <div class="txt">
        <div class="t">{{ $l['title'] }}</div>
        @if($l['l1'])<div class="l1">{{ $l['l1'] }}</div>@endif
        @if($L['showL2'] && $l['l2'])<div class="l2">{{ $l['l2'] }}</div>@endif
        @if($tape >= 24)<div class="co">{{ strtoupper($company) }} · {{ $l['tag'] }}</div>@endif
    </div>
</div>
@endforeach
</body></html>
