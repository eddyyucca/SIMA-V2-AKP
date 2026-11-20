<x-filament-panels::page>
    @php
        $a = $record->loadMissing(['category', 'employee.department', 'location', 'vendor']);
        $st = \App\Models\Asset::STATUSES; $cd = \App\Models\Asset::CONDITIONS;
        $money = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $card = 'background:var(--c-card,rgba(127,127,127,.08));border-radius:12px;padding:16px;margin-bottom:16px';
    @endphp
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px">
        <div style="{{ $card }}">
            <h3 style="font-weight:700;font-size:1.1rem;margin-bottom:8px">{{ $a->asset_tag }} — {{ $a->brand }} {{ $a->model }}</h3>
            <table style="width:100%;font-size:14px">
                <tr><td style="color:#888;width:40%">Kategori</td><td>{{ $a->category?->name }}</td></tr>
                <tr><td style="color:#888">Serial</td><td>{{ $a->serial_number ?: '-' }}</td></tr>
                <tr><td style="color:#888">Status</td><td><b>{{ $st[$a->status] ?? $a->status }}</b></td></tr>
                <tr><td style="color:#888">Kondisi</td><td>{{ $cd[$a->condition] ?? $a->condition }}</td></tr>
                <tr><td style="color:#888">Pemegang saat ini</td><td><b>{{ $a->employee?->name ?? '-' }}</b> {{ $a->employee?->department?->name ? '(' . $a->employee->department->name . ')' : '' }}</td></tr>
                <tr><td style="color:#888">Lokasi</td><td>{{ $a->location?->name ?? '-' }}</td></tr>
                <tr><td style="color:#888">Tgl beli</td><td>{{ $a->purchase_date?->format('d/m/Y') ?? '-' }}</td></tr>
                <tr><td style="color:#888">Harga</td><td>{{ $money($a->purchase_price) }}</td></tr>
                <tr><td style="color:#888">Vendor / No. PO/PR</td><td>{{ $a->vendor?->name ?? '-' }} / {{ $a->po_pr_no ?: '-' }}</td></tr>
                <tr><td style="color:#888">Garansi s/d</td><td>{{ $a->warranty_until?->format('d/m/Y') ?? '-' }}</td></tr>
            </table>
            @if(! empty($a->spec_data))
                <table style="width:100%;font-size:13px;margin-top:10px;border-top:1px solid rgba(127,127,127,.25)">
                    <tr><td colspan="2" style="padding-top:8px;font-weight:700">Spesifikasi</td></tr>
                    @foreach(\App\Models\Asset::specFields() as $key => $f)
                        @if(! empty($a->spec_data[$key]))<tr><td style="color:#888;width:35%;padding:2px 0">{{ $f[0] }}</td><td>{{ $a->spec_data[$key] }}</td></tr>@endif
                    @endforeach
                </table>
            @endif
            @if($a->specs)<pre style="white-space:pre-wrap;font-size:12px;margin-top:8px;color:#888">{{ $a->specs }}</pre>@endif
        </div>
        <div style="{{ $card }};text-align:center">
            {!! SimpleSoftwareIO\QrCode\Facades\QrCode::size(160)->margin(1)->generate($a->asset_tag) !!}
            <div style="font-weight:700;margin-top:4px">{{ $a->asset_tag }}</div>
            @if($a->displayPhoto())<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($a->displayPhoto()) }}" style="max-height:140px;margin:8px auto;border-radius:8px">@endif
            @foreach(($a->documents ?? []) as $doc)
                <div><a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($doc) }}" target="_blank" style="color:#1f78b0;font-size:13px"><svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m18.38 11.69-7.8 7.8a4.5 4.5 0 0 1-6.36-6.36l9.2-9.2a3 3 0 0 1 4.24 4.24l-9.2 9.2a1.5 1.5 0 0 1-2.12-2.12l7.8-7.8"/></svg> {{ basename($doc) }}</a></div>
            @endforeach
        </div>
    </div>

    @if($a->ownership === 'rental')
        @php
            $left = $a->rental_days_left;
            $months = ($a->rental_start && $a->rental_end) ? max(1, (int) round($a->rental_start->diffInMonths($a->rental_end))) : 0;
            $leftColor = $a->status === 'returned' ? '#888' : ($left < 0 ? '#dc2626' : ($left <= 30 ? '#1f78b0' : '#16a34a'));
        @endphp
        <div style="{{ $card }};border-left:4px solid #8b5cf6">
            <h4 style="font-weight:700;margin-bottom:8px">Aset Sewa (Rental)</h4>
            <table style="width:100%;font-size:14px">
                <tr><td style="color:#888;width:35%">Penyedia sewa</td><td>{{ $a->vendor?->name ?? '-' }}</td></tr>
                <tr><td style="color:#888">No. kontrak</td><td>{{ $a->rental_contract_no ?: '-' }}</td></tr>
                <tr><td style="color:#888">Masa sewa</td><td>{{ $a->rental_start?->format('d/m/Y') }} – {{ $a->rental_end?->format('d/m/Y') }} ({{ $months }} bulan)</td></tr>
                <tr><td style="color:#888">Sisa waktu</td><td style="color:{{ $leftColor }};font-weight:700">
                    @if($a->status === 'returned') Sudah dikembalikan @elseif($left < 0) Lewat jatuh tempo {{ abs($left) }} hari @else {{ $left }} hari lagi @endif</td></tr>
                <tr><td style="color:#888">Biaya per bulan</td><td>{{ $money($a->rental_monthly_cost) }}</td></tr>
                <tr><td style="color:#888">Total kontrak</td><td>{{ $money($a->rental_monthly_cost * $months) }}</td></tr>
                <tr><td style="color:#888">Deposit</td><td>{{ $money($a->rental_deposit) }}</td></tr>
                <tr><td style="color:#888">Status kontrak</td><td><b style="color:{{ $leftColor }}">{{ $a->rental_contract_status }}</b></td></tr>
                @if($a->status === 'returned')
                    <tr><td style="color:#888">Dikembalikan pada</td><td><b>{{ $a->returned_at?->format('d/m/Y') }}</b> — {{ $a->return_notes ?: '-' }}</td></tr>
                @endif
            </table>
            @php $contracts = $a->rentalContracts()->with('uploader')->get(); @endphp
            @foreach($a->setRelation('rentalContracts', $contracts)->currentContracts() as $rows)
                <h5 style="font-weight:700;margin:14px 0 4px">{{ $rows[0]->label }}
                    @if($rows->isNotEmpty())<span style="font-size:12px;font-weight:700;color:{{ $rows[0]->isActive() ? '#16a34a' : '#dc2626' }}">· {{ $rows[0]->statusLabel() }}</span>@endif
                </h5>
                <table style="width:100%;font-size:13px">
                    @forelse($rows as $c)
                        <tr>
                            <td>{{ \App\Models\RentalContract::KINDS[$c->kind] ?? $c->kind }}@if($c->contract_no) · {{ $c->contract_no }}@endif<br>
                                <span style="color:#888">{{ $c->start_date->format('d/m/Y') }} – {{ $c->end_date->format('d/m/Y') }}{{ $c->note ? ' · ' . $c->note : '' }}</span></td>
                            <td style="color:{{ $c->isActive() ? '#16a34a' : '#dc2626' }};font-weight:600">{{ $c->statusLabel() }}</td>
                            <td style="white-space:nowrap;text-align:right"><a href="{{ route('contract.view', $c) }}" target="_blank" style="color:#1f78b0;font-weight:600">Lihat</a> · <a href="{{ route('contract.download', $c) }}" style="color:#1f78b0;font-weight:600">Unduh</a></td>
                        </tr>
                    @empty
                        <tr><td style="color:#888">-</td></tr>
                    @endforelse
                </table>
            @endforeach
            @if($contracts->isEmpty())<p style="font-size:13px;color:#888;margin-top:12px">Belum ada berkas kontrak. Gunakan tombol Lainnya → Upload Kontrak Sewa.</p>@endif
        </div>
    @endif

    @php $res = $a->loadMissing('reservationItem.plan')->reservationItem; @endphp
    @if($res?->plan)
        <div style="{{ $card }};border-left:4px solid #29aae1">
            <h4 style="font-weight:700;margin-bottom:4px">Dicadangkan untuk rencana kedatangan</h4>
            <p style="font-size:14px">Karyawan <b>{{ $res->plan->name }}</b> — tiba {{ $res->plan->arrival_date->format('d/m/Y') }} di {{ $res->plan->location?->name ?? '-' }}
                (<a href="{{ \App\Filament\Resources\ArrivalPlans\ArrivalPlanResource::getUrl('view', ['record' => $res->plan]) }}" style="color:#1f78b0">{{ $res->plan->plan_no }}</a>).
                Status barang: <b>{{ \App\Models\ArrivalPlan::ITEM_STATUSES[$res->status] }}</b>. Aset ini tidak bisa diserahkan ke orang lain sampai rencana selesai atau dibatalkan.</p>
        </div>
    @endif

    @include('filament.depreciation-card', ['a' => $a, 'card' => $card])

    <div style="{{ $card }}">
        <h4 style="font-weight:700;margin-bottom:6px">Komponen terpasang <span style="font-weight:400;font-size:12px;color:#888">(kelengkapan aset; tampil di BAST pemegang)</span></h4>
        @php $bastNow = app(\App\Services\AssetService::class)->currentHolderBast($a); @endphp
        <ul style="font-size:14px;list-style:disc;padding-left:20px">
            @forelse($a->installedComponentsDetailed() as $c)
                <li>{{ $c['component']?->name }} × {{ rtrim(rtrim(number_format($c['qty'], 2), '0'), '.') }}
                    <span style="color:#888;font-size:12px">@if($c['installed_at']) · dipasang {{ $c['installed_at']->format('d/m/Y') }}@endif @if($c['po']) · PO {{ $c['po'] }}@endif @if($c['cost'] > 0) · Rp {{ number_format($c['cost'], 0, ',', '.') }}@endif</span>
                    @if($c['doc'])<a href="{{ route('accessory-bast.view', $c['doc']) }}" target="_blank" style="color:#1f78b0;font-size:12px"> · {{ $c['doc']->bast_number }}</a>@endif</li>
            @empty<li style="list-style:none;color:#888">Belum ada</li>@endforelse
        </ul>
        @if($bastNow)<p style="font-size:12px;color:#888;margin-top:6px">BAST pemegang: <a href="{{ route('bast.view', $bastNow) }}" target="_blank" style="color:#1f78b0">{{ $bastNow->bast_number }}</a> — kelengkapan per {{ $bastNow->equipmentAsOf()->format('d/m/Y') }}@if(app(\App\Services\AssetService::class)->equipmentOutdated($a)) <b style="color:#d97706">(belum memuat komponen terbaru)</b>@endif</p>@endif
    </div>

    @php $installs = $a->installHandovers()->with(['items.stockable', 'employee', 'pic', 'creator'])->withCount('files')->get(); @endphp
    <div style="{{ $card }};overflow-x:auto">
        <h4 style="font-weight:700;margin-bottom:6px">Riwayat pemasangan komponen (upgrade / perbaikan)</h4>
        <table style="width:100%;font-size:13px;min-width:700px">
            <thead><tr style="text-align:left;color:#888"><th>Tanggal</th><th>Dokumen</th><th>Alasan</th><th>Komponen</th><th>Pemegang saat itu</th><th>Dikerjakan</th><th>TTD</th></tr></thead>
            <tbody>
            @forelse($installs as $ih)
                <tr style="border-top:1px solid rgba(127,127,127,.2)">
                    <td style="white-space:nowrap">{{ $ih->handed_at->format('d/m/Y') }}</td>
                    <td style="white-space:nowrap"><a href="{{ route('accessory-bast.view', $ih) }}" target="_blank" style="color:#1f78b0;font-weight:600">{{ $ih->bast_number }}</a></td>
                    <td>{{ $ih->reason ?: '-' }}</td>
                    <td>{{ $ih->itemsSummary() }}@foreach(($ih->meta['spec_changes'] ?? []) as $sc)<br><span style="color:#888">{{ $sc['label'] }}: {{ $sc['from'] ?: '-' }} → {{ $sc['to'] }}</span>@endforeach</td>
                    <td>{{ $ih->employee?->name ?? 'Belum ada pemegang' }}@if($ih->pic)<br><span style="color:#888">Peminta: {{ $ih->pic->name }}</span>@endif</td>
                    <td>{{ $ih->creator?->name ?? '-' }}</td>
                    <td style="white-space:nowrap">{!! $ih->files_count > 0 ? '<span style="color:#059669">TTD ✓</span>' : '<span style="color:#d97706">Belum TTD</span>' !!}</td>
                </tr>
            @empty<tr><td colspan="7" style="color:#888">Belum ada pemasangan komponen</td></tr>@endforelse
            </tbody>
        </table>
    </div>

    <div style="{{ $card }};overflow-x:auto">
        <h4 style="font-weight:700;margin-bottom:6px">Riwayat pemegang (serah terima, mutasi, pengembalian)</h4>
        <table style="width:100%;font-size:13px;min-width:600px">
            <thead><tr style="text-align:left;color:#888"><th>Tanggal</th><th>Jenis</th><th>Dari</th><th>Kepada</th><th>Kondisi</th><th>BAST</th></tr></thead>
            <tbody>
            @forelse($a->assignments()->with(['from', 'to', 'bastFiles'])->get() as $as)
                <tr>
                    <td>{{ $as->assigned_at->format('d/m/Y H:i') }}</td>
                    <td>{{ \App\Models\AssetAssignment::TYPES[$as->type] }}</td>
                    <td>{{ $as->from?->name ?? 'Gudang IT' }}</td>
                    <td>{{ $as->to?->name ?? 'Gudang IT' }}</td>
                    <td>{{ $cd[$as->condition] ?? $as->condition }}</td>
                    <td style="white-space:nowrap">{{ $as->bast_number }} · <a href="{{ route('bast.view', $as) }}" target="_blank" style="color:#1f78b0">Lihat</a> · <a href="{{ route('bast.download', $as) }}" style="color:#1f78b0">Unduh</a>@foreach($as->bastFiles as $bf) · <a href="{{ route('bast.file', $bf) }}" target="_blank" style="color:#1f78b0">Scan TTD{{ $as->bastFiles->count() > 1 ? ' ' . $loop->iteration : '' }}</a>@endforeach</td>
                </tr>
            @empty<tr><td colspan="6" style="color:#888">Belum ada riwayat</td></tr>@endforelse
            </tbody>
        </table>
    </div>

    <div style="{{ $card }};overflow-x:auto">
        <h4 style="font-weight:700;margin-bottom:6px">Riwayat perbaikan</h4>
        <table style="width:100%;font-size:13px;min-width:600px">
            <thead><tr style="text-align:left;color:#888"><th>Tanggal</th><th>Kerusakan</th><th>Tindakan</th><th>Biaya</th><th>Garansi</th><th>Status</th></tr></thead>
            <tbody>
            @forelse($a->repairs as $r)
                <tr>
                    <td>{{ $r->reported_at->format('d/m/Y') }}</td><td>{{ $r->issue }}</td><td>{{ $r->action ?: '-' }}</td>
                    <td>{{ $money($r->cost) }}</td><td>{{ $r->warranty_claim ? 'Klaim' : '-' }}</td>
                    <td>{{ ['reported' => 'Dilaporkan', 'in_progress' => 'Diproses', 'done' => 'Selesai'][$r->status] }}</td>
                </tr>
            @empty<tr><td colspan="6" style="color:#888">Belum ada perbaikan</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
        <div style="{{ $card }}">
            <h4 style="font-weight:700;margin-bottom:6px">Lisensi pada aset ini</h4>
            <ul style="font-size:14px;list-style:disc;padding-left:20px">
                @forelse(\App\Models\LicenseSeat::with('license')->where('asset_id', $a->id)->whereNull('revoked_at')->get() as $s)
                    <li>{{ $s->license?->name }} <span style="color:#888">(sejak {{ $s->assigned_at->format('d/m/Y') }})</span></li>
                @empty<li style="list-style:none;color:#888">Belum ada</li>@endforelse
            </ul>
        </div>
        <div style="{{ $card }}">
            <h4 style="font-weight:700;margin-bottom:6px">Pemakaian consumable untuk aset ini</h4>
            <ul style="font-size:14px;list-style:disc;padding-left:20px">
                @forelse(\App\Models\StockMovement::with('stockable')->where('asset_id', $a->id)->where('stockable_type', \App\Models\Consumable::class)->where('type', 'out')->latest('moved_at')->get() as $m)
                    <li>{{ $m->stockable?->name }} × {{ rtrim(rtrim(number_format($m->qty, 2), '0'), '.') }} <span style="color:#888">({{ $m->moved_at->format('d/m/Y') }})</span></li>
                @empty<li style="list-style:none;color:#888">Belum ada</li>@endforelse
            </ul>
        </div>
    </div>
    @php
        $hb = $a->agentHeartbeat; $tokenActive = $a->agentTokens()->whereNull('revoked_at')->exists();
    @endphp
    @if ($hb || $tokenActive)
        @php
            $alerts = app(\App\Services\AgentAlertRules::class)->evaluate($a, $hb, app(\App\Services\AgentUpdateManifest::class)->latestVersion());
            $gb = fn ($b) => $b ? number_format($b / 1073741824, 1, ',', '.') . ' GB' : '-';
            $stLabel = ['online' => 'Online', 'offline' => 'Offline', 'never_reported' => 'Belum pernah lapor'][$a->agent_status] ?? $a->agent_status;
        @endphp
        <div style="{{ $card }}">
            <h3 style="font-weight:700;font-size:1.1rem;margin-bottom:8px">Perangkat (Agent) - <span style="color:{{ $a->agent_status === 'online' ? '#16a34a' : '#d64545' }}">{{ $stLabel }}</span></h3>
            <table style="width:100%;font-size:14px">
                <tr><td style="color:#888;width:30%">Laporan terakhir</td><td>{{ $a->agent_last_seen?->format('d/m/Y H:i') ?? '-' }} {{ $a->agent_last_seen ? '(' . $a->agent_last_seen->diffForHumans() . ')' : '' }}</td></tr>
                @if ($hb)
                    <tr><td style="color:#888">Hostname / IP</td><td>{{ $hb->hostname ?: '-' }} / {{ $hb->ip_address ?: '-' }}</td></tr>
                    <tr><td style="color:#888">OS</td><td>{{ $hb->os_version ?: '-' }} {{ $hb->os_build }}</td></tr>
                    <tr><td style="color:#888">CPU / RAM</td><td>{{ $hb->cpu ?: '-' }} / {{ $gb($hb->ram_total_bytes) }}</td></tr>
                    <tr><td style="color:#888">VGA</td><td>{{ collect($hb->gpus ?? [])->map(fn ($g) => $g['name'] . (! empty($g['vram_bytes']) ? ' (' . $gb($g['vram_bytes']) . ')' : ''))->implode(', ') ?: '-' }}</td></tr>
                    <tr><td style="color:#888">Disk bebas / total</td><td>{{ $gb($hb->disk_free_bytes) }} / {{ $gb($hb->disk_total_bytes) }}</td></tr>
                    <tr><td style="color:#888">BitLocker / Defender</td><td>{{ $hb->bitlocker_status ?: '-' }} / {{ $hb->defender_enabled === null ? '-' : ($hb->defender_enabled ? 'aktif' : 'nonaktif') }}</td></tr>
                    <tr><td style="color:#888">Versi agent</td><td>{{ $hb->agent_version ?: '-' }}</td></tr>
                @endif
                <tr><td style="color:#888">Token agent</td><td>{{ $tokenActive ? 'Aktif' : 'Tidak ada' }}</td></tr>
            </table>
            @if ($alerts)
                <div style="margin-top:8px;font-size:13px">
                    @foreach ($alerts as $al)
                        <div style="color:{{ $al['severity'] === 'critical' ? '#d64545' : ($al['severity'] === 'warning' ? '#d9a02b' : '#888') }}">&bull; {{ trans('agent.alert_' . $al['code'], $al['params']) }}</div>
                    @endforeach
                </div>
            @endif
            @php $cmds = $a->agentCommands()->limit(5)->get(); @endphp
            @if ($cmds->isNotEmpty())
                <div style="margin-top:10px;font-size:13px"><b>Perintah terakhir</b>
                    @foreach ($cmds as $c)
                        <div>{{ $c->requested_at?->format('d/m H:i') }} - {{ str($c->type)->replace('_', ' ') }}: <b>{{ $c->status }}</b>{{ $c->result ? ' - ' . \Illuminate\Support\Str::limit($c->result, 80) : '' }}</div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
