@php $link = 'color:#1f78b0;font-weight:600'; @endphp
<div style="font-size:13px">
    <table style="width:100%">
        <thead><tr style="text-align:left;color:#888"><th>Diunggah</th><th>File</th><th>Sumber</th><th>Keterangan</th><th></th></tr></thead>
        <tbody>
        @forelse($documents as $d)
            <tr>
                <td>{{ $d['at']->format('d/m/Y H:i') }}<br><span style="color:#888">{{ $d['by'] }}</span></td>
                <td>{{ $d['name'] }}</td>
                <td>{{ $d['source'] }}</td>
                <td>{{ $d['note'] ?: '-' }}</td>
                <td style="white-space:nowrap;text-align:right"><a href="{{ $d['view'] }}" target="_blank" style="{{ $link }}">Lihat</a> · <a href="{{ $d['download'] }}" style="{{ $link }}">Unduh</a></td>
            </tr>
        @empty<tr><td colspan="5" style="color:#888">Belum ada dokumen.</td></tr>@endforelse
        </tbody>
    </table>
</div>
