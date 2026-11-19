@php
    $link = 'color:#1f78b0;font-weight:600';
    $generated = $asset->assignments()->whereNotNull('bast_pdf_path')->get();
    $files = $asset->bastFiles()->with(['assignment', 'uploader'])->latest('id')->get();
@endphp
<div style="font-size:13px">
    <h4 style="font-weight:700;margin-bottom:6px">BAST dari sistem ({{ $generated->count() }})</h4>
    <table style="width:100%;margin-bottom:18px">
        <thead><tr style="text-align:left;color:#888"><th>No. BAST</th><th>Jenis</th><th>Tanggal</th><th></th></tr></thead>
        <tbody>
        @forelse($generated as $g)
            <tr>
                <td>{{ $g->bast_number }}</td>
                <td>{{ \App\Models\AssetAssignment::TYPES[$g->type] }}</td>
                <td>{{ $g->assigned_at->format('d/m/Y') }}</td>
                <td style="white-space:nowrap;text-align:right"><a href="{{ route('bast.view', $g) }}" target="_blank" style="{{ $link }}">Lihat</a> · <a href="{{ route('bast.download', $g) }}" style="{{ $link }}">Unduh</a></td>
            </tr>
        @empty<tr><td colspan="4" style="color:#888">Belum ada</td></tr>@endforelse
        </tbody>
    </table>

    <h4 style="font-weight:700;margin-bottom:6px">PDF bertanda tangan yang diunggah ({{ $files->count() }})</h4>
    <table style="width:100%">
        <thead><tr style="text-align:left;color:#888"><th>Diunggah</th><th>Untuk BAST</th><th>Keterangan</th><th></th></tr></thead>
        <tbody>
        @forelse($files as $f)
            <tr>
                <td>{{ $f->created_at->format('d/m/Y H:i') }}<br><span style="color:#888">{{ $f->uploader?->name }}</span></td>
                <td>{{ $f->assignment?->bast_number ?? '-' }}</td>
                <td>{{ $f->note ?: '-' }}</td>
                <td style="white-space:nowrap;text-align:right"><a href="{{ route('bast.file', $f) }}" target="_blank" style="{{ $link }}">Lihat</a> · <a href="{{ route('bast.file.download', $f) }}" style="{{ $link }}">Unduh</a></td>
            </tr>
        @empty<tr><td colspan="4" style="color:#888">Belum ada unggahan. Gunakan tombol Upload BAST.</td></tr>@endforelse
        </tbody>
    </table>
</div>
