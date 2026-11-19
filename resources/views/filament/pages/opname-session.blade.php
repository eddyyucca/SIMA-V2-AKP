<x-filament-panels::page>
    @php
        $op = $this->opname;
        $items = $op->items()->with('asset')->orderByDesc('scanned_at')->get();
        $expected = \App\Models\Asset::where('location_id', $op->location_id)->whereNotIn('status', \App\Models\Asset::INACTIVE)->count();
        $R = \App\Models\StockOpnameItem::RESULTS;
        $color = ['found' => '#16a34a', 'wrong_location' => '#1f78b0', 'missing' => '#dc2626'];
    @endphp
    <div style="font-size:14px">
        <b>Lokasi:</b> {{ $op->location->name }} &middot; <b>Status:</b> {{ $op->status === 'running' ? 'Berjalan' : 'Selesai' }}
        &middot; <b>Terscan:</b> {{ $items->whereIn('result', ['found', 'wrong_location'])->count() }} / {{ $expected }} aset tercatat
    </div>
    @if($op->status === 'running')
        <div style="max-width:560px;margin-top:12px">
            <x-scanner />
            <div style="margin-top:12px;display:flex;gap:8px">
                <input type="text" wire:model="code" wire:keydown.enter="submitManual" placeholder="Ketik kode aset / serial lalu Enter"
                       style="flex:1;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;background:transparent;color:inherit">
                <button type="button" wire:click="submitManual" style="padding:8px 16px;border-radius:8px;background:#374151;color:#fff">Tambah</button>
            </div>
            <button type="button" wire:click="finish" wire:confirm="Tutup sesi? Aset yang belum ter-scan akan ditandai Tidak Ditemukan."
                    style="margin-top:12px;padding:8px 16px;border-radius:8px;background:#dc2626;color:#fff;font-weight:600">Tutup sesi & buat laporan selisih</button>
        </div>
    @else
        <div style="margin-top:12px">
            <a href="{{ route('reports.download', ['key' => 'opname', 'format' => 'xlsx', 'opname' => $op->id]) }}" style="color:#1f78b0">⬇ Excel</a> &nbsp;
            <a href="{{ route('reports.download', ['key' => 'opname', 'format' => 'pdf', 'opname' => $op->id]) }}" style="color:#1f78b0">⬇ PDF</a>
        </div>
    @endif
    <table style="width:100%;font-size:13px;margin-top:16px">
        <thead><tr style="text-align:left;color:#888"><th>Aset</th><th>Hasil</th><th>Catatan</th><th>Waktu</th></tr></thead>
        <tbody>
        @forelse($items as $i)
            <tr><td>{{ $i->asset->asset_tag }} — {{ $i->asset->brand }} {{ $i->asset->model }}</td>
                <td style="color:{{ $color[$i->result] }};font-weight:600">{{ $R[$i->result] }}</td>
                <td>{{ $i->notes }}</td><td>{{ $i->scanned_at?->format('H:i:s') }}</td></tr>
        @empty<tr><td colspan="4" style="color:#888">Belum ada yang di-scan</td></tr>@endforelse
        </tbody>
    </table>
</x-filament-panels::page>
