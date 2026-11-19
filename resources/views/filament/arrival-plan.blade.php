<x-filament-panels::page>
    @php
        $p = $record->loadMissing(['items.asset', 'items.accessory', 'items.assignment', 'department', 'location', 'pic', 'employee']);
        $pr = $p->progress();
        $pct = $pr['total'] ? round($pr['ready'] / $pr['total'] * 100) : 0;
        $card = 'background:rgba(127,127,127,.08);border-radius:12px;padding:16px;margin-bottom:16px';
        $badge = fn ($s) => ['planned' => '#6b7280', 'prepared' => '#1f78b0', 'ready' => '#16a34a', 'handed_over' => '#0284c7', 'cancelled' => '#dc2626'][$s] ?? '#6b7280';
        $left = $p->days_left;
        $manage = auth()->user()->can('manage inventory') && $p->isActive();
        $btn = 'padding:4px 10px;border-radius:8px;font-size:12px;font-weight:600;border:1px solid rgba(127,127,127,.4);margin-left:4px';
    @endphp

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
        <div style="{{ $card }}">
            <h3 style="font-weight:700;font-size:1.15rem;margin-bottom:8px">{{ $p->name }}
                <span style="font-size:12px;font-weight:700;color:#fff;background:{{ $badge($p->status) }};padding:2px 10px;border-radius:99px;margin-left:6px">{{ \App\Models\ArrivalPlan::STATUSES[$p->status] }}</span></h3>
            <table style="width:100%;font-size:14px">
                <tr><td style="color:#888;width:38%">No. rencana</td><td>{{ $p->plan_no }}</td></tr>
                <tr><td style="color:#888">NIK</td><td>{{ $p->nik ?: '-' }}</td></tr>
                <tr><td style="color:#888">Department / jabatan</td><td>{{ $p->department?->name ?? '-' }} / {{ $p->position ?: '-' }}</td></tr>
                <tr><td style="color:#888">Lokasi onsite</td><td>{{ $p->location?->name ?? '-' }}</td></tr>
                <tr><td style="color:#888">Tanggal tiba</td><td><b>{{ $p->arrival_date->format('d/m/Y') }}</b>
                    @if($p->isActive()) — <span style="color:{{ $left < 0 ? '#dc2626' : ($left <= 3 ? '#1f78b0' : '#0284c7') }};font-weight:700">{{ $left < 0 ? 'lewat ' . abs($left) . ' hari' : ($left === 0 ? 'hari ini' : $left . ' hari lagi') }}</span>@endif</td></tr>
                <tr><td style="color:#888">PIC IT</td><td>{{ $p->pic?->name ?? '-' }}</td></tr>
                <tr><td style="color:#888">Email / telepon</td><td>{{ $p->email ?: '-' }} / {{ $p->phone ?: '-' }}</td></tr>
                @if($p->notes)<tr><td style="color:#888">Catatan</td><td>{{ $p->notes }}</td></tr>@endif
                @if($p->status === 'handed_over')<tr><td style="color:#888">Diserahkan</td><td>{{ $p->handed_over_at?->format('d/m/Y H:i') }} — karyawan: <b>{{ $p->employee?->name }}</b></td></tr>@endif
                @if($p->status === 'cancelled')<tr><td style="color:#888">Alasan batal</td><td>{{ $p->cancel_reason ?: '-' }}</td></tr>@endif
            </table>
        </div>
        <div style="{{ $card }}">
            <h4 style="font-weight:700;margin-bottom:6px">Kesiapan barang</h4>
            <div style="font-size:2rem;font-weight:800;letter-spacing:-.03em">{{ $pr['ready'] }} <span style="font-size:1rem;font-weight:600;color:#888">dari {{ $pr['total'] }} siap</span></div>
            <div style="height:12px;border-radius:99px;background:rgba(127,127,127,.2);overflow:hidden;margin:8px 0"><span style="display:block;height:100%;width:{{ $pct }}%;background:linear-gradient(90deg,#16a34a,#84cc16)"></span></div>
            <p style="font-size:12px;color:#888">Direncanakan → Disiapkan → Siap → Diserahkan. Barang yang dicadangkan tidak bisa diserahkan ke orang lain.</p>
        </div>
    </div>

    <div style="{{ $card }};overflow-x:auto">
        <h4 style="font-weight:700;margin-bottom:8px">Daftar barang</h4>
        <table style="width:100%;font-size:13px;min-width:640px">
            <thead><tr style="text-align:left;color:#888"><th>Jenis</th><th>Barang</th><th>Status</th><th>Catatan</th><th style="text-align:right">{{ $manage ? 'Aksi' : '' }}</th></tr></thead>
            <tbody>
            @forelse($p->items->where('status', '!=', 'cancelled') as $it)
                @php $problem = $it->problem(); @endphp
                <tr style="border-top:1px solid rgba(127,127,127,.2)">
                    <td>{{ $it->type === 'asset' ? 'Aset' : 'Accessory' }}</td>
                    <td><b>{{ $it->label() }}</b>@if($problem)<div style="color:#dc2626;font-size:12px">⚠ {{ $problem }}</div>@endif</td>
                    <td><span style="color:#fff;background:{{ $badge($it->status) }};padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700">{{ \App\Models\ArrivalPlan::ITEM_STATUSES[$it->status] }}</span></td>
                    <td>
                        @if($it->status === 'handed_over' && $it->assignment)
                            {{ $it->assignment->bast_number }}
                            @if($it->assignment->bast_pdf_path) · <a href="{{ route('bast.view', $it->assignment) }}" target="_blank" style="color:#1f78b0">Lihat</a> · <a href="{{ route('bast.download', $it->assignment) }}" style="color:#1f78b0">Unduh</a>@endif
                        @else {{ $it->note }} @endif
                    </td>
                    <td style="text-align:right;white-space:nowrap">
                        @if($manage)
                            @if($it->status === 'planned')<button type="button" wire:click="markItem({{ $it->id }}, 'prepared')" style="{{ $btn }}">Siapkan</button>@endif
                            @if($it->status === 'prepared')<button type="button" wire:click="markItem({{ $it->id }}, 'ready')" style="{{ $btn }};background:#16a34a;color:#fff">Tandai Siap</button>
                                <button type="button" wire:click="markItem({{ $it->id }}, 'planned')" style="{{ $btn }}">Mundur</button>@endif
                            @if($it->status === 'ready')<button type="button" wire:click="markItem({{ $it->id }}, 'prepared')" style="{{ $btn }}">Batal Siap</button>@endif
                            <button type="button" wire:click="removeItem({{ $it->id }})" wire:confirm="Keluarkan barang ini dari rencana?" style="{{ $btn }};color:#dc2626">Hapus</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="color:#888;padding:12px 0">Belum ada barang. Gunakan <b>Tambah Aset</b> atau <b>Tambah Accessory</b> di kanan atas.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
