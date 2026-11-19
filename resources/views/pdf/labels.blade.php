<!doctype html>
<html lang="id"><head><meta charset="utf-8">
<style>
 @page{margin:8mm}
 body{font-family:DejaVu Sans,sans-serif;font-size:9px}
 .l{display:inline-block;width:62mm;height:30mm;border:1px dashed #888;margin:0 2mm 2mm 0;padding:2mm;vertical-align:top;overflow:hidden}
 .l img{width:24mm;height:24mm;float:left;margin-right:2mm}
 .t{font-size:12px;font-weight:bold}
</style></head><body>
@foreach($assets as $a)
 <div class="l">
  <img src="data:image/svg+xml;base64,{{ base64_encode(SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(200)->margin(0)->generate($a->asset_tag)) }}">
  <div class="t">{{ $a->asset_tag }}</div>
  <div>{{ $a->brand }} {{ $a->model }}</div>
  <div>SN: {{ $a->serial_number ?: '-' }}</div>
  <div>{{ config('app.name') }}</div>
 </div>
@endforeach
</body></html>
