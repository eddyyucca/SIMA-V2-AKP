@php
    $rows = $item->receipts()->with(['vendor', 'creator'])->orderByDesc('received_at')->orderByDesc('id')->get();
    $qty = $rows->sum('qty');
    $spend = $rows->sum(fn ($r) => (float) $r->qty * (float) $r->unit_price);
    $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $n = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
@endphp
<div class="text-sm" style="overflow-x:auto">
    <table style="width:100%;min-width:560px;border-collapse:collapse">
        <thead><tr style="text-align:left;color:#888"><th>Tanggal</th><th>Vendor</th><th>No. PO</th><th style="text-align:right">Jumlah</th><th style="text-align:right">Harga satuan</th><th style="text-align:right">Total</th></tr></thead>
        <tbody>
        @forelse($rows as $r)
            <tr style="border-top:1px solid rgba(127,127,127,.2)">
                <td>{{ $r->received_at->format('d/m/Y') }}</td><td>{{ $r->vendor?->name ?? '-' }}</td><td>{{ $r->reference_no ?: '-' }}</td>
                <td style="text-align:right">{{ $n($r->qty) }}</td><td style="text-align:right">{{ $rp($r->unit_price) }}</td><td style="text-align:right">{{ $rp($r->total) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="color:#888;padding:10px 0">Belum ada pembelian tercatat.</td></tr>
        @endforelse
        </tbody>
        @if($rows->count())
        <tfoot><tr style="border-top:2px solid rgba(127,127,127,.4);font-weight:700">
            <td colspan="3">Total {{ $rows->count() }} pembelian</td><td style="text-align:right">{{ $n($qty) }}</td>
            <td style="text-align:right">rata-rata {{ $rp($qty ? $spend / $qty : 0) }}</td><td style="text-align:right">{{ $rp($spend) }}</td>
        </tr></tfoot>
        @endif
    </table>
</div>
