@php $handovers = $handovers ?? collect(); @endphp
<div style="margin-top:14px">
    <b>Riwayat serah terima barang pendukung</b>
    <table style="width:100%;font-size:12px;margin-top:4px">
        @forelse($handovers as $x)
            <tr style="border-top:1px solid rgba(127,127,127,.2)">
                <td style="padding:3px 6px 3px 0;white-space:nowrap">{{ $x->handed_at->format('d/m/Y') }}</td>
                <td style="padding:3px 6px"><a href="{{ route('accessory-bast.view', $x) }}" target="_blank" style="color:#1f78b0;font-weight:600">{{ $x->bast_number }}</a></td>
                <td style="padding:3px 6px">{{ \App\Models\AccessoryHandover::TYPES[$x->type] ?? $x->type }}</td>
                <td style="padding:3px 6px">{{ $x->itemsSummary() }}</td>
                <td style="padding:3px 0;white-space:nowrap">{!! ($x->files_count ?? $x->files->count()) > 0 ? '<span style="color:#059669">TTD ✓</span>' : '<span style="color:#1f78b0">Belum TTD</span>' !!}</td>
            </tr>
        @empty
            <tr><td style="color:#888">Belum ada</td></tr>
        @endforelse
    </table>
</div>
