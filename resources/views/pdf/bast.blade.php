<!doctype html>
<html lang="id"><head><meta charset="utf-8">
@php
    $form = $form ?? false;
    $d = \App\Support\BastDocument::build($a, $form);
    $logo = \App\Support\Branding::logoDataUri();
@endphp
<style>
 @page { size: A4 portrait; margin: 9mm 9mm 8mm 9mm; }
 * { box-sizing: border-box; }
 body { font-family: DejaVu Sans, sans-serif; font-size: 7pt; color: #000; margin: 0; }
 table { width: 100%; border-collapse: collapse; table-layout: fixed; }
 td, th { border: 1px solid #000; padding: 2.3px 6px; vertical-align: middle; text-align: left; }
 .sec { margin-top: 6px; }
 .sec th.h { background: #d9d9d9; font-weight: bold; font-size: 7.4pt; padding: 3.4px 6px; border-bottom: 1.6px solid #000; }
 .lbl { width: 24%; }
 .up { text-transform: uppercase; }
 .c { text-align: center; }
 .b { font-weight: bold; }
 .grid th { background: #fff; font-weight: bold; text-align: center; font-size: 6.8pt; padding: 2.6px 3px; }
 .grid td { text-align: center; font-size: 6.8pt; padding: 3px 3px; }
 .noborder, .noborder td { border: 0; padding: 0; }
 .sig th { text-align: center; font-weight: bold; background: #fff; padding: 4.5px 3px; font-size: 7.4pt; }
 .sig td { text-align: center; }
 .sig td.space { height: 21mm; vertical-align: bottom; padding: 2px; }
 .sig td.space img { max-height: 19mm; max-width: 100%; }
 .sig td.nm { font-weight: bold; font-size: 6.8pt; padding: 4px 3px; text-transform: uppercase; height: 9mm; }
 ol { margin: 0; padding: 0 0 0 4mm; }
 ol li { margin: 0 0 1px 0; line-height: 1.28; }
@if(! empty($d['compact']))
 .sig td.space { height: 13mm; }
 .sig td.space img { max-height: 11mm; }
 .sec { margin-top: 3px; }
 ol li { line-height: 1.18; }
 .eq td { padding: 2px 4px; }
@endif
</style></head><body>

{{-- KOP --}}
<table>
 <tr>
  <td style="width:22%;text-align:center;padding:4px">@if($logo)<img src="{{ $logo }}" style="width:27mm" alt="AKP">@endif</td>
  <td style="width:46%;text-align:center;padding:4px">
    <div style="font-size:12.5pt;font-weight:bold;margin-bottom:5px" class="up">{{ $d['company'] }}</div>
    <div style="font-size:8.6pt;font-weight:bold">{{ $d['title'] }}</div>
  </td>
  <td style="width:32%;padding:0">
    <table class="noborder">
     @foreach([['No. Dokumen', $d['number']], ['Tanggal', $d['date']], ['Revisi', $d['revision']], ['Status', $d['status']]] as $r)
     <tr>
       <td style="width:37%;font-weight:bold;font-size:6.4pt;padding:3.4px 5px;border-bottom:{{ $loop->last ? '0' : '1px solid #000' }}">{{ $r[0] }}</td>
       <td style="width:5%;font-size:6.8pt;padding:3.4px 0;border-bottom:{{ $loop->last ? '0' : '1px solid #000' }}">:</td>
       <td style="font-size:6.6pt;padding:3.4px 4px;border-bottom:{{ $loop->last ? '0' : '1px solid #000' }}">{{ $r[1] }}</td>
     </tr>
     @endforeach
    </table>
  </td>
 </tr>
</table>

{{-- PENYERAH --}}
<table class="sec" style="table-layout:auto">
 <colgroup><col style="width:24%"><col></colgroup>
 <tr><th class="h" colspan="2">DATA PENYERAH</th></tr>
 <tr><td class="lbl">Nama</td><td class="up">{{ $d['giver']['name'] }}</td></tr>
 <tr><td class="lbl">NIK</td><td>{{ $d['giver']['nik'] }}</td></tr>
 <tr><td class="lbl">Jabatan</td><td class="up">{{ $d['giver']['position'] }}</td></tr>
</table>

{{-- PENERIMA --}}
<table class="sec" style="table-layout:auto">
 <colgroup><col style="width:24%"><col></colgroup>
 <tr><th class="h" colspan="2">DATA PENERIMA</th></tr>
 <tr><td class="lbl">Nama</td><td class="up">{{ $d['taker']['name'] }}</td></tr>
 <tr><td class="lbl">NIK</td><td>{{ $d['taker']['nik'] }}</td></tr>
 <tr><td class="lbl">Jabatan</td><td class="up">{{ $d['taker']['position'] }}</td></tr>
 <tr><td class="lbl">Department</td><td class="up">{{ $d['taker']['dept'] }}</td></tr>
</table>

{{-- DETAIL ASSET --}}
<table class="sec"><tr><th class="h" style="text-align:left;font-size:7.4pt;padding:3.4px 6px;background:#d9d9d9;border-bottom:1.6px solid #000">DETAIL ASSET</th></tr></table>
<table class="grid">
 <tr>
  <th style="width:5%">No</th><th style="width:14%">Asset Name</th><th style="width:12%">Kategori</th><th style="width:16%">Purchase Order</th>
  <th style="width:13%">Manufacturer</th><th style="width:16%">Merk / Model</th><th style="width:13%">Purchase Cost</th><th style="width:11%">Kondisi</th>
 </tr>
 <tr>
  <td>1</td><td>{{ $d['asset']['name'] }}</td><td class="up">{{ $d['asset']['category'] }}</td><td>{{ $d['asset']['po'] }}</td>
  <td>{{ $d['asset']['manufacturer'] }}</td><td class="up">{{ $d['asset']['model'] }}</td><td>{{ $d['asset']['cost'] }}</td><td>{{ $d['asset']['condition'] }}</td>
 </tr>
</table>

{{-- IDENTITAS ASSET --}}
<table class="sec" style="table-layout:auto">
 <tr><th class="h" colspan="3">IDENTITAS ASSET</th></tr>
 <tr>
  <td class="c" style="width:33%;height:{{ ! empty($d['compact']) ? '17mm' : '33mm' }};padding:3px">@if($d['photo'])<img src="{{ $d['photo'] }}" style="max-width:56mm;max-height:{{ ! empty($d['compact']) ? '15mm' : '30mm' }}" alt="Foto asset">@else<span style="color:#888;font-size:7pt">Foto asset belum diunggah</span>@endif</td>
  <td class="c" style="width:22%;padding:4px">
    <div class="b" style="font-size:8.6pt;margin-bottom:2px">ASSET TAG:</div>
    <div style="font-size:8pt;margin-bottom:9px">{{ $d['asset']['tag'] }}</div>
    <div class="b" style="font-size:8.6pt;margin-bottom:2px">SERIAL NUMBER:</div>
    <div style="font-size:8pt">{{ $d['asset']['serial'] }}</div>
  </td>
  <td class="c" style="width:45%;padding:6px">
    <table style="width:78mm;margin:0 auto;border:2.2px solid #000;table-layout:fixed">
     <tr>
      <td style="width:70%;border:0;border-right:1.6px solid #000;border-bottom:1.6px solid #000;padding:3px 5px;font-weight:bold;font-size:8pt;text-align:left;white-space:nowrap;overflow:hidden">{{ $d['label']['company'] }}</td>
      <td rowspan="3" style="width:30%;border:0;padding:2px;text-align:center"><img src="{{ $d['label']['qr'] }}" style="width:17mm;height:17mm" alt="QR"></td>
     </tr>
     <tr><td style="border:0;border-right:1.6px solid #000;border-bottom:1.6px solid #000;padding:3px 5px;font-weight:bold;font-size:7.2pt;text-align:left;white-space:nowrap;overflow:hidden">{{ $d['label']['tag'] }}</td></tr>
     <tr><td style="border:0;border-right:1.6px solid #000;padding:3px 5px;font-weight:bold;font-size:7.2pt;text-align:left;white-space:nowrap;overflow:hidden">{{ $d['label']['serial'] }}</td></tr>
    </table>
  </td>
 </tr>
</table>

{{-- KELENGKAPAN --}}
<table class="sec"><tr><th class="h" style="text-align:left;font-size:7.4pt;padding:3.4px 6px;background:#d9d9d9;border-bottom:1.6px solid #000">KELENGKAPAN ASSET</th></tr></table>
<table class="grid eq">
 <tr><th style="width:6%">No</th><th style="width:34%">Nama Item</th><th style="width:20%">Purchase Order Item</th><th style="width:20%">Purchase Cost Item</th><th style="width:20%">Status Item</th></tr>
 @forelse($d['extras'] as $x)
 <tr><td>{{ $loop->iteration }}</td><td>{{ $x['name'] }}</td><td>{{ $x['po'] }}</td><td>{{ $x['cost'] }}</td><td>{{ $x['status'] }}</td></tr>
 @empty
 <tr><td colspan="5">Tidak Ada Kelengkapan Asset</td></tr>
 @endforelse
 @if(! empty($d['extrasNote']))<tr><td colspan="5" style="font-size:6.4pt;text-align:left;color:#333">{{ $d['extrasNote'] }}</td></tr>@endif
</table>

{{-- PERNYATAAN --}}
<table class="sec" style="table-layout:auto">
 <tr><th class="h">PERNYATAAN DAN TANGGUNG JAWAB</th></tr>
 <tr><td style="padding:4px 8px 2px 8px"><ol>@foreach($d['statements'] as $s)<li>{{ $s }}</li>@endforeach</ol></td></tr>
</table>

{{-- TANDA TANGAN --}}
<table class="sec sig">
 <tr><th class="h" colspan="7" style="text-align:left;background:#d9d9d9;font-size:7.4pt;padding:3.4px 6px;border-bottom:1.6px solid #000">TANDA TANGAN</th></tr>
 <tr><th style="width:16%">Menyerahkan</th><th style="width:16%">Menerima</th><th style="width:17%">Menyetujui</th><th colspan="4">Mengetahui</th></tr>
 <tr>
  <th>{{ $d['sig']['giver_role'] }}</th><th>{{ $d['sig']['taker_role'] }}</th>
  <th>{{ $d['approvers'][0][1] }}</th><th style="width:12.7%">{{ $d['approvers'][1][1] }}</th><th style="width:12.7%">{{ $d['approvers'][2][1] }}</th><th style="width:12.7%">{{ $d['approvers'][3][1] }}</th><th style="width:12.9%">{{ $d['approvers'][4][1] }}</th>
 </tr>
 <tr>
  <td class="space">@if($d['sig']['giver_role'] === 'IT'){!! $sigIt ? '<img src="' . e($sigIt) . '">' : '' !!}@else{!! $sigReceiver ? '<img src="' . e($sigReceiver) . '">' : '' !!}@endif</td>
  <td class="space">@if($d['sig']['taker_role'] === 'IT'){!! $sigIt ? '<img src="' . e($sigIt) . '">' : '' !!}@else{!! $sigReceiver ? '<img src="' . e($sigReceiver) . '">' : '' !!}@endif</td>
  <td class="space"></td><td class="space"></td><td class="space"></td><td class="space"></td><td class="space"></td>
 </tr>
 <tr>
  <td class="nm">{{ $d['sig']['giver_name'] }}</td><td class="nm">{{ $d['sig']['taker_name'] }}</td>
  @foreach($d['approvers'] as $ap)<td class="nm">{{ $ap[2] }}</td>@endforeach
 </tr>
</table>
</body></html>
