<!doctype html>
<html lang="id"><head><meta charset="utf-8">
<style>
 body{font-family:DejaVu Sans,sans-serif;font-size:10px}
 h1{font-size:15px} table{width:100%;border-collapse:collapse} td,th{border:1px solid #999;padding:3px 5px;text-align:left} th{background:#eee}
</style></head><body>
@include('pdf.partials.brand-header')
<h1>{{ $title }}</h1>
<div>Dicetak {{ now()->format('d/m/Y H:i') }} WITA</div><br>
<table><thead><tr>@foreach($head as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
<tbody>@foreach($rows as $r)<tr>@foreach($r as $c)<td>{{ $c }}</td>@endforeach</tr>@endforeach</tbody></table>
</body></html>
