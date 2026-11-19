<!doctype html>
<html lang="id"><head><meta charset="utf-8">
<style>
 @page { size: A4 portrait; margin: 12mm 11mm 10mm 11mm; }
 * { box-sizing: border-box; }
 body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #000; margin: 0; }
 table { width: 100%; border-collapse: collapse; table-layout: fixed; }
 td, th { border: 1px solid #000; padding: 3px 7px; vertical-align: middle; text-align: left; }
 .sec { margin-top: 10px; }
 .sec th.h { background: #d9d9d9; font-weight: bold; font-size: 8.4pt; padding: 4px 7px; border-bottom: 1.6px solid #000; }
 .lbl { width: 24%; }
 .up { text-transform: uppercase; }
 .c { text-align: center; }
 .grid th { background: #fff; font-weight: bold; text-align: center; font-size: 7.4pt; padding: 3px 4px; }
 .grid td { font-size: 7.6pt; padding: 4px; }
 .grid tr.total td { background: #ececec; font-weight: bold; text-align: right; }
 .noborder, .noborder td { border: 0; padding: 0; }
 .sig th { background: #ececec; text-align: center; font-weight: bold; padding: 5px 3px; font-size: 8pt; }
 .sig td { text-align: center; }
 .sig td.space { height: 24mm; }
 .sig td.nm { font-weight: bold; font-size: 7.6pt; padding: 5px 3px; height: 8mm; }
 @if(! empty($d['compact']))
 body { font-size: 7.6pt; }
 td, th { padding: 2px 6px; }
 .sec { margin-top: 5px; }
 .sec th.h { padding: 2.5px 6px; font-size: 8pt; }
 .grid td { padding: 2.5px 4px; font-size: 7.3pt; }
 .grid th { padding: 2.5px 4px; }
 .sig td.space { height: 16mm; }
 .sig td.nm { height: 6mm; padding: 3px; }
 .sig th { padding: 3px; }
 .foot { margin-top: 10px !important; }
 @endif
</style></head><body>

<table>
 <tr>
  <td style="width:17%;text-align:center;padding:5px">@if($d['logo'])<img src="{{ $d['logo'] }}" style="width:24mm" alt="AKP">@endif</td>
  <td style="width:45%;text-align:center;padding:5px">
    <div style="font-size:11.6pt;font-weight:bold;margin-bottom:6px;white-space:nowrap" class="up">{{ $d['company'] }}</div>
    <div style="font-size:9pt;font-weight:bold">{{ $d['title'] }}</div>
  </td>
  <td style="width:38%;padding:0">
    <table class="noborder">
     @foreach([['No. Dokumen', $d['number']], ['Tanggal', $d['date']], ['Referensi', $d['ticket']], ['Status', $d['status']]] as $r)
     <tr>
       <td style="width:30%;font-weight:bold;font-size:6.6pt;padding:3.6px 5px;white-space:nowrap">{{ $r[0] }}</td>
       <td style="width:4%;font-size:7pt;padding:3.6px 0">:</td>
       <td style="font-size:6.8pt;padding:3.6px 3px;white-space:nowrap">{{ $r[1] }}</td>
     </tr>
     @endforeach
    </table>
  </td>
 </tr>
</table>

@foreach($d['blocks'] as $block)
<table class="sec" style="table-layout:auto">
 <colgroup><col style="width:24%"><col></colgroup>
 <tr><th class="h" colspan="2">{{ $block['label'] }}</th></tr>
 @foreach($block['rows'] as [$rl, $rv, $rUp])
 <tr><td class="lbl">{{ $rl }}</td><td @if($rUp)class="up"@endif>{{ $rv }}</td></tr>
 @endforeach
</table>
@endforeach

<table class="sec"><tr><th class="h" style="background:#d9d9d9;text-align:left;font-size:8.4pt;padding:4px 7px;border-bottom:1.6px solid #000">{{ $d['tableTitle'] }}</th></tr></table>
<table class="grid">
 <tr>
  <th style="width:4.5%">No</th><th>Nama Barang</th><th style="width:12%">Item No.</th><th style="width:15%">Kategori</th><th style="width:7%">Qty</th><th style="width:21%">Catatan</th>
 </tr>
 @foreach($d['rows'] as $r)
 <tr>
  <td class="c">{{ $loop->iteration }}</td><td class="up">{{ $r['name'] }}</td><td class="c">{{ $r['code'] }}</td><td class="up">{{ $r['category'] }}</td><td class="c">{{ $r['qty'] }}</td><td>{{ $r['remark'] }}</td>
 </tr>
 @endforeach
 <tr class="total"><td colspan="4">{{ $d['totalLabel'] }}</td><td class="c" style="text-align:center">{{ $d['total'] }}</td><td></td></tr>
</table>

@if(! empty($d['specChanges']))
<table class="sec"><tr><th class="h" style="background:#d9d9d9;text-align:left;font-size:8.4pt;padding:4px 7px;border-bottom:1.6px solid #000">PERUBAHAN SPESIFIKASI ASET</th></tr></table>
<table class="grid">
 <tr><th style="width:24%">Kolom</th><th style="width:38%">Sebelum</th><th>Sesudah</th></tr>
 @foreach($d['specChanges'] as $c)
 <tr><td class="c">{{ $c['label'] }}</td><td>{{ $c['from'] }}</td><td><b>{{ $c['to'] }}</b></td></tr>
 @endforeach
</table>
@endif

<p style="margin:7px 0 0 0;font-size:7.6pt;line-height:1.4">{{ $d['statement'] }}</p>

@if($d['notes'])
<table class="sec"><tr><th class="h">CATATAN</th></tr><tr><td>{{ $d['notes'] }}</td></tr></table>
@endif

<table class="sec sig">
 <tr><th colspan="3" style="background:#d9d9d9;text-align:left;font-size:8.4pt;padding:4px 7px;border-bottom:1.6px solid #000">TANDA TANGAN</th></tr>
 <tr><th style="width:33.3%">{{ $d['sign'][0] }}</th><th style="width:33.3%">{{ $d['sign'][1] }}</th><th style="width:33.4%">{{ $d['sign'][2] }}</th></tr>
 <tr><td class="space"></td><td class="space"></td><td class="space"></td></tr>
 <tr><td class="nm {{ $d['giver']['upName'] ? 'up' : '' }}">{{ $d['giver']['name'] }}</td><td class="nm {{ $d['receiver']['upName'] ? 'up' : '' }}">{{ $d['receiver']['name'] }}</td><td class="nm up">{{ $d['known'] }}</td></tr>
</table>

<p class="foot" style="margin-top:22px;text-align:center;font-size:6.8pt;line-height:1.45;color:#111">
  Dokumen ini merupakan dokumen resmi yang dikendalikan oleh IT {{ $d['companyName'] }}.<br>
  Dilarang memperbanyak, mendistribusikan, atau menggunakan sebagian maupun seluruh isi dokumen tanpa persetujuan tertulis dari perusahaan.
</p>
</body></html>
