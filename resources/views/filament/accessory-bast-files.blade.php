@php $link = 'color:#1f78b0;font-weight:600'; @endphp
<div style="font-size:13px">
    <table style="width:100%">
        <thead><tr style="text-align:left;color:#888"><th>Diunggah</th><th>File</th><th>Keterangan</th><th></th></tr></thead>
        <tbody>
        @forelse($handover->files()->with('uploader')->get() as $f)
            <tr>
                <td>{{ $f->created_at->format('d/m/Y H:i') }}<br><span style="color:#888">{{ $f->uploader?->name }}</span></td>
                <td>{{ $f->original_name ?: basename($f->path) }}</td>
                <td>{{ $f->note ?: '-' }}</td>
                <td style="white-space:nowrap;text-align:right"><a href="{{ route('accessory-bast.file', $f) }}" target="_blank" style="{{ $link }}">Lihat</a> · <a href="{{ route('accessory-bast.file.download', $f) }}" style="{{ $link }}">Unduh</a></td>
            </tr>
        @empty<tr><td colspan="4" style="color:#888">Belum ada unggahan.</td></tr>@endforelse
        </tbody>
    </table>
</div>
