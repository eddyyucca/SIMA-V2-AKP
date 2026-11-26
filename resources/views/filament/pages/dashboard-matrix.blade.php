{{-- Matriks utilitas aset: baris = kategori / lokasi; kolom = Dipakai, Perbaikan, Tersedia, Dipakai tapi tanpa nama, Total. Setiap angka menaut ke daftar Aset
     dengan filter yang sama (jumlah di daftar = angka di sel). $m = Dashboard::statusMatrix(), $by = 'category' | 'location', $rowLabel = judul kolom pertama. --}}
@php
    $idKey = $by === 'location' ? 'location_id' : 'category_id';
    $cell = function (array $r, string $status, ?string $extra = null) use ($m, $by, $idKey) {
        $f = ['aktif' => true] + ($r['id'] ? [$idKey => $r['id']] : []);
        if ($status !== 'total') $f['status'] = $status;
        if ($extra === 'nh') $f['tanpa_pemegang'] = true;
        return $this->assetsUrl($f);
    };
    $cols = [['deployed', 'Dipakai', null], ['repair', 'Perbaikan', null], ['available', 'Tersedia', null], ['deployed_nh', 'Dipakai tapi tanpa nama', 'nh']];
@endphp
<div style="overflow-x:auto;border:1px solid var(--line);border-radius:12px">
    <table class="pd-mx">
        <thead>
            <tr>
                <th rowspan="2" class="l">{{ $rowLabel }}</th>
                <th colspan="4">Utilitas</th>
                <th rowspan="2">Total</th>
            </tr>
            <tr>@foreach($cols as [$k, $label])<th>{{ $label }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @forelse($m['rows'] as $r)
                <tr>
                    <td class="l">@if($r['id'])<a class="pd-mxl" href="{{ $cell($r, 'total') }}">{{ $r['name'] }}</a>@else{{ $r['name'] }}@endif</td>
                    @foreach($cols as [$k, $label, $extra])
                        @php $n = $r[$k]; $warn = $k === 'deployed_nh' && $n > 0; @endphp
                        <td class="{{ $n === 0 ? 'z' : '' }} {{ $warn ? 'warn' : '' }}">@if($n > 0)<a class="pd-mxl" href="{{ $cell($r, $extra ? 'deployed' : $k, $extra) }}">{{ number_format($n) }}</a>@else 0 @endif</td>
                    @endforeach
                    <td class="t">@if($r['id'])<a class="pd-mxl" href="{{ $cell($r, 'total') }}">{{ number_format($r['total']) }}</a>@else{{ number_format($r['total']) }}@endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="z" style="padding:22px;text-align:center">Belum ada aset.</td></tr>
            @endforelse
        </tbody>
        @if(count($m['rows']))
            <tfoot>
                <tr>
                    <td class="l">Total</td>
                    @foreach($cols as [$k, $label, $extra])
                        <td class="{{ $k === 'deployed_nh' && $m['total'][$k] > 0 ? 'warn' : '' }}">{{ number_format($m['total'][$k]) }}</td>
                    @endforeach
                    <td class="t">{{ number_format($m['total']['total']) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
