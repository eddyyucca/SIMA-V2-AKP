<!doctype html>
<html lang="id"><head><meta charset="utf-8">
<style>
 body{font-family:DejaVu Sans,sans-serif;font-size:12px}
 h1{font-size:18px;text-align:center;margin:0} .c{text-align:center;color:#555;margin-bottom:14px}
 table{width:100%;border-collapse:collapse;margin:8px 0} td,th{border:1px solid #999;padding:5px 7px;text-align:left} th{background:#eee;width:30%}
</style></head><body>
@include('pdf.partials.brand-header')
<h1>BERITA ACARA PENGHAPUSAN ASET</h1>
<div class="c" style="margin-bottom:2px"><strong>No. Dokumen: {{ $d->documentNumber() }}</strong></div>
<div class="c">Disetujui {{ $d->approved_at?->format('d/m/Y H:i') }} WITA</div>
<table>
 <tr><th>Kode Aset</th><td>{{ $d->asset->asset_tag }}</td></tr>
 <tr><th>Merek / Model</th><td>{{ $d->asset->brand }} {{ $d->asset->model }}</td></tr>
 <tr><th>Serial Number</th><td>{{ $d->asset->serial_number ?: '-' }}</td></tr>
 <tr><th>Alasan</th><td>{{ $d->reason }}</td></tr>
 <tr><th>Data sudah di-wipe</th><td>{{ $d->data_wiped ? 'Ya' : 'Tidak' }}</td></tr>
 <tr><th>Metode</th><td>{{ \App\Models\Disposal::METHODS[$d->method] ?? $d->method }}</td></tr>
 @php
    $dep = app(\App\Services\DepreciationService::class);
    $book = $dep->bookValueAtRetirement($d->asset);
    $sale = (float) $d->sale_price;
    $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
@endphp
 <tr><th>Harga perolehan</th><td>{{ $rp($d->asset->purchase_price) }}</td></tr>
 <tr><th>Nilai buku saat dilepas</th><td>{{ $book === null ? '-' : $rp($book) }}</td></tr>
 @if(in_array($d->method, ['sell', 'auction']))
 <tr><th>Harga jual / lelang</th><td>{{ $rp($sale) }}</td></tr>
 <tr><th>Laba / (rugi) pelepasan</th><td>{{ $book === null ? '-' : $rp($sale - $book) }}</td></tr>
 @endif
 <tr><th>Diajukan oleh</th><td>{{ $d->requester?->name }}</td></tr>
 <tr><th>Disetujui oleh</th><td>{{ $d->approver?->name }}</td></tr>
</table>
</body></html>
