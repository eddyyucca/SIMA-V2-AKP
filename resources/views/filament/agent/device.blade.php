<x-filament-panels::page>
    <x-am.styles />
    @php
        $dash = '—';
        $gb = fn ($b) => $b ? number_format($b / 1073741824, $b >= 107374182400 ? 0 : 1, ',', '.') . ' GB' : $dash;
        $ago = fn ($t) => $t ? $t->diffForHumans() : null;
        $on = fn ($v) => $v === true ? ['Aktif', 'ok'] : ($v === false ? ['Nonaktif', 'bad'] : ['Tidak diketahui', '']);
        $tone = ['critical' => 'bad', 'warning' => 'warn', 'info' => 'info'];
        $barTone = fn ($p) => $p >= 90 ? 'bad' : ($p >= 75 ? 'warn' : '');
        $status = $hb === null ? 'never_reported' : ($a->agent_status ?: 'never_reported');
        $statusLabel = ['online' => ['Online', 'ok'], 'offline' => ['Offline', ''], 'never_reported' => ['Belum pernah lapor', 'info']][$status] ?? [$status, ''];
        $ramUsed = ($hb && $hb->ram_total_bytes && $hb->ram_free_bytes !== null) ? (int) round(($hb->ram_total_bytes - $hb->ram_free_bytes) / $hb->ram_total_bytes * 100) : null;
        $tabs = ['summary' => 'tab_summary', 'cpu' => 'tab_cpu_ram', 'storage' => 'tab_storage', 'network' => 'tab_network', 'security' => 'tab_security', 'software' => 'tab_software', 'actions' => 'tab_actions', 'changes' => 'tab_changes'];
        $cmdTone = ['pending' => 'warn', 'sent' => 'info', 'done' => 'ok', 'failed' => 'bad', 'expired' => '', 'cancelled' => ''];
        $catLabel = fn ($c) => trans('agent.cat_' . $c) !== 'agent.cat_' . $c ? trans('agent.cat_' . $c) : $c;
        $fieldLabel = fn ($f) => trans('agent.field_' . $f) !== 'agent.field_' . $f ? trans('agent.field_' . $f) : $f;
    @endphp

    <div wire:poll.30s x-data="{ tab: (location.hash || '#summary').slice(1) }" class="am-stack">
        {{-- Kepala: identitas + status --}}
        <div class="am-kpis" style="margin-bottom:0">
            <x-am.kpi :label="trans('agent.col_status')" :value="$statusLabel[0]" :tone="$statusLabel[1] ?: 'neutral'" :sub="trans('agent.kpi_last_report').': '.($ago($a->agent_last_seen) ?? $dash)" />
            <x-am.kpi :label="trans('agent.col_user')" :value="$a->employee?->name ?: $dash" :sub="$a->employee?->department?->name" />
            <x-am.kpi :label="trans('agent.kpi_cpu')" :value="$hb?->cpu_load_percent !== null ? $hb->cpu_load_percent.'%' : $dash" />
            <x-am.kpi :label="trans('agent.kpi_ram')" :value="$ramUsed !== null ? $ramUsed.'%' : $dash" :tone="$ramUsed !== null ? ($ramUsed >= 90 ? 'bad' : ($ramUsed >= 75 ? 'warn' : 'ok')) : 'neutral'" :sub="$hb ? $gb($hb->ram_total_bytes) : null" />
            <x-am.kpi :label="trans('agent.kpi_disk')" :value="$disk ? $disk['percent'].'%' : $dash" :tone="$disk ? ($disk['percent'] >= 90 ? 'bad' : ($disk['percent'] >= 75 ? 'warn' : 'ok')) : 'neutral'" :sub="$disk['drive'] ?? null" />
            <x-am.kpi :label="trans('agent.kpi_changes')" :value="$changesTotal" :sub="trans('agent.kpi_changes_sub')" />
        </div>

        @if (count($alerts))
            <div class="am-card">
                <h4>{{ trans('agent.col_alerts') }} ({{ count($alerts) }})</h4>
                @foreach ($alerts as $al)
                    <div style="margin:4px 0;font-size:13px"><span class="am-pill {{ $tone[$al['severity']] ?? '' }}">{{ trans('agent.sev_'.$al['severity']) }}</span>&nbsp; {{ trans('agent.alert_'.$al['code'], $al['params']) }}</div>
                @endforeach
                <p class="am-muted" style="margin:10px 0 0">{{ trans('agent.alerts_note') }}</p>
            </div>
        @endif

        @if (! $hb)
            <div class="am-callout">{{ trans('agent.no_data') }}</div>
        @else
            <div class="am-tabs">
                @foreach ($tabs as $key => $label)
                    <button type="button" :class="{ on: tab === '{{ $key }}' }" x-on:click="tab = '{{ $key }}'; history.replaceState(null, '', '#{{ $key }}')">{{ trans('agent.'.$label) }}@if($key === 'software' && $hb->software_count) <span class="am-pill" style="margin-left:4px">{{ $hb->software_count }}</span>@endif</button>
                @endforeach
            </div>

            {{-- RINGKASAN --}}
            <div x-show="tab === 'summary'" class="am-grid2">
                <div class="am-card"><h4>{{ trans('agent.section_identity') }}</h4>
                    <dl class="am-kv">
                        <dt>{{ trans('agent.hostname') }}</dt><dd>{{ $hb->hostname ?: $dash }}</dd>
                        <dt>{{ trans('agent.domain') }}</dt><dd>{{ $hb->domain ?: $dash }}</dd>
                        <dt>{{ trans('agent.manufacturer') }}</dt><dd>{{ $hb->manufacturer ?: $dash }}</dd>
                        <dt>{{ trans('agent.model') }}</dt><dd>{{ $hb->model ?: $dash }}</dd>
                        <dt>{{ trans('agent.serial_number') }}</dt><dd>{{ $hb->serial_number ?: $dash }} @if($hb->serialMismatchesAsset())<span class="am-pill warn">≠ {{ $a->serial_number }}</span>@endif</dd>
                        <dt>{{ trans('agent.agent_version') }}</dt><dd>{{ $hb->agent_version ? 'v'.$hb->agent_version : $dash }} @if($latest && (int) $hb->agent_version < $latest)<span class="am-pill warn">v{{ $latest }}</span>@endif</dd>
                        <dt>{{ trans('agent.ip') }}</dt><dd>{{ $hb->ip_address ?: $dash }}</dd>
                    </dl></div>
                <div class="am-card"><h4>{{ trans('agent.section_os') }}</h4>
                    <dl class="am-kv">
                        <dt>{{ trans('agent.os') }}</dt><dd>{{ $hb->os_version ? str_replace('Microsoft ', '', $hb->os_version) : $dash }}</dd>
                        <dt>{{ trans('agent.os_build') }}</dt><dd>{{ $hb->os_build ?: $dash }} {{ $hb->os_architecture }}</dd>
                        <dt>{{ trans('agent.last_boot') }}</dt><dd>{{ $hb->last_boot_at ? $hb->last_boot_at->format('d/m/Y H:i') . ' (' . trans('agent.up_for', ['time' => $hb->last_boot_at->diffForHumans(null, true)]) . ')' : $dash }}</dd>
                        <dt>{{ trans('agent.kpi_last_report') }}</dt><dd>{{ $hb->reported_at?->format('d/m/Y H:i') ?? $dash }}</dd>
                        <dt>{{ trans('agent.benchmark_at') }}</dt><dd>{{ $hb->benchmark_at?->format('d/m/Y H:i') ?? $dash }}</dd>
                        <dt>Aset</dt><dd><a href="{{ \App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]) }}" style="font-weight:600">{{ $a->asset_tag }}</a> · {{ trim(($a->brand ?? '').' '.($a->model ?? '')) }}</dd>
                    </dl></div>
            </div>

            {{-- PROSESOR & MEMORI --}}
            <div x-show="tab === 'cpu'" x-cloak class="am-stack">
                <div class="am-grid2">
                    <div class="am-card"><h4>{{ trans('agent.section_cpu') }}</h4>
                        <dl class="am-kv">
                            <dt>{{ trans('agent.cpu_model') }}</dt><dd>{{ $hb->cpu ?: $dash }}</dd>
                            <dt>{{ trans('agent.cpu_vendor') }}</dt><dd>{{ $hb->cpu_manufacturer ?: $dash }}</dd>
                            <dt>{{ trans('agent.cpu_cores') }} / {{ trans('agent.cpu_threads') }}</dt><dd>{{ $hb->cpu_cores ?: $dash }} / {{ $hb->cpu_logical ?: $dash }}</dd>
                            <dt>{{ trans('agent.cpu_base_clock') }}</dt><dd>{{ $hb->cpu_max_mhz ? number_format($hb->cpu_max_mhz / 1000, 2, ',', '.') . ' GHz' : $dash }}</dd>
                            <dt>{{ trans('agent.cpu_cache_l2') }} / {{ trans('agent.cpu_cache_l3') }}</dt><dd>{{ $hb->cpu_l2_kb ? round($hb->cpu_l2_kb / 1024, 1).' MB' : $dash }} / {{ $hb->cpu_l3_kb ? round($hb->cpu_l3_kb / 1024, 1).' MB' : $dash }}</dd>
                            <dt>{{ trans('agent.cpu_load') }}</dt><dd>{{ $hb->cpu_load_percent !== null ? $hb->cpu_load_percent.'%' : $dash }}</dd>
                        </dl></div>
                    <div class="am-card"><h4>{{ trans('agent.section_ram') }}</h4>
                        <dl class="am-kv">
                            <dt>{{ trans('agent.ram_total') }}</dt><dd>{{ $gb($hb->ram_total_bytes) }}</dd>
                            <dt>{{ trans('agent.ram_usage') }}</dt><dd>@if($ramUsed !== null){{ $ramUsed }}% · {{ trans('agent.free_gb', ['size' => number_format($hb->ram_free_bytes / 1073741824, 1, ',', '.')]) }}<x-am.bar :value="$ramUsed" :tone="$barTone($ramUsed)" />@else{{ $dash }}@endif</dd>
                            <dt>{{ trans('agent.ram_slots') }}</dt><dd>{{ $hb->ram_slots_total ? trans('agent.slots_used', ['used' => count($hb->ram_modules ?? []), 'total' => $hb->ram_slots_total]) : $dash }}</dd>
                            <dt>{{ trans('agent.ram_max') }}</dt><dd>{{ $gb($hb->ram_max_bytes) }}</dd>
                            <dt>{{ trans('agent.ram_read') }} / {{ trans('agent.ram_write') }}</dt><dd>{{ $hb->ram_read_mbps ? number_format($hb->ram_read_mbps, 0, ',', '.') . ' MB/s' : $dash }} / {{ $hb->ram_write_mbps ? number_format($hb->ram_write_mbps, 0, ',', '.') . ' MB/s' : $dash }}</dd>
                        </dl>
                        @if($hb->benchmark_at)<p class="am-muted" style="margin:8px 0 0">{{ trans('agent.benchmark_note', ['time' => $hb->benchmark_at->diffForHumans()]) }}</p>@endif</div>
                </div>
                <div class="am-card"><h4>{{ trans('agent.section_gpu') }}</h4>
                    @if (empty($hb->gpus))
                        <p class="am-muted" style="margin:0">{{ trans('agent.gpu_none') }}</p>
                    @else
                        <table class="am-table"><thead><tr><th>{{ trans('agent.gpu_name') }}</th><th>{{ trans('agent.gpu_kind') }}</th><th>{{ trans('agent.gpu_vram') }}</th><th>{{ trans('agent.gpu_driver') }}</th><th>{{ trans('agent.gpu_resolution') }}</th><th>{{ trans('agent.gpu_status') }}</th></tr></thead><tbody>
                        @foreach ($hb->gpus as $g)
                            @php $k = $g['kind'] ?? 'unknown'; @endphp
                            <tr><td><strong>{{ $g['name'] }}</strong>@if(! empty($g['processor']) && $g['processor'] !== $g['name'])<div class="am-muted">{{ $g['processor'] }}</div>@endif</td>
                                <td><span class="am-pill {{ $k === 'discrete' ? 'info' : '' }}">{{ trans('agent.gpu_kind_'.$k) }}</span></td>
                                <td>{{ ! empty($g['vram_bytes']) ? $gb($g['vram_bytes']) : $dash }}</td>
                                <td>{{ $g['driver_version'] ?? $dash }}@if(! empty($g['driver_date']))<div class="am-muted">{{ \Illuminate\Support\Str::limit($g['driver_date'], 10, '') }}</div>@endif</td>
                                <td>{{ ! empty($g['resolution']) ? $g['resolution'] . (! empty($g['refresh_hz']) ? ' @ ' . $g['refresh_hz'] . ' Hz' : '') : $dash }}</td>
                                <td>{{ $g['status'] ?? $dash }}</td></tr>
                        @endforeach</tbody></table>
                        <p class="am-muted" style="margin:8px 0 0">{{ trans('agent.gpu_vram_note') }}</p>
                    @endif</div>
                @if (! empty($hb->ram_modules))
                    <div class="am-card"><h4>{{ trans('agent.section_ram_modules') }}</h4>
                        <table class="am-table"><thead><tr><th>{{ trans('agent.slot') }}</th><th>{{ trans('agent.capacity') }}</th><th>{{ trans('agent.type') }}</th><th>{{ trans('agent.speed') }}</th><th>{{ trans('agent.form_factor') }}</th><th>{{ trans('agent.vendor') }}</th><th>{{ trans('agent.part_number') }}</th></tr></thead>
                        <tbody>@foreach ($hb->ram_modules as $m)<tr><td>{{ $m['slot'] ?? $dash }}</td><td>{{ $gb($m['capacity'] ?? null) }}</td><td>{{ $m['type'] ?? $dash }}</td><td>{{ isset($m['speed_mhz']) ? $m['speed_mhz'].' MHz' : $dash }}</td><td>{{ $m['form_factor'] ?? $dash }}</td><td>{{ $m['manufacturer'] ?? $dash }}</td><td>{{ $m['part_number'] ?? $dash }}</td></tr>@endforeach</tbody></table>
                    </div>
                @endif
            </div>

            {{-- PENYIMPANAN --}}
            <div x-show="tab === 'storage'" x-cloak class="am-stack">
                <div class="am-card"><h4>{{ trans('agent.section_drives') }}</h4>
                    @forelse ($hb->disks ?? [] as $d)
                        @php $tot = (int) ($d['total'] ?? 0); $free = (int) ($d['free'] ?? 0); $p = $tot > 0 ? (int) round(($tot - $free) / $tot * 100) : 0; @endphp
                        <div class="am-row"><span class="r-label" style="flex-basis:12%"><strong>{{ $d['drive'] }}</strong></span><span class="r-bar"><x-am.bar :value="$p" :tone="$barTone($p)" /></span>
                            <span class="r-num" style="flex-basis:240px;font-weight:500">{{ $p }}% · {{ trans('agent.free') }} {{ $gb($free) }} / {{ $gb($tot) }}</span></div>
                    @empty<p class="am-muted" style="margin:0">{{ trans('agent.no_data_section') }}</p>@endforelse</div>
                <div class="am-grid2">
                    <div class="am-card"><h4>{{ trans('agent.section_physical_disks') }}</h4>
                        <table class="am-table"><thead><tr><th>{{ trans('agent.item') }}</th><th>{{ trans('agent.type') }}</th><th>{{ trans('agent.capacity') }}</th><th>{{ trans('agent.health') }}</th></tr></thead><tbody>
                        @forelse ($hb->physical_disks ?? [] as $d)
                            @php $h = $d['health'] ?? 'unknown'; @endphp
                            <tr><td>{{ $d['name'] }}</td><td>{{ $d['media'] ?? $dash }}</td><td>{{ $gb($d['size'] ?? null) }}</td><td><span class="am-pill {{ $h === 'healthy' ? 'ok' : ($h === 'warning' ? 'warn' : ($h === 'unhealthy' ? 'bad' : '')) }}">{{ $h }}</span></td></tr>
                        @empty<tr><td colspan="4" class="am-muted">{{ trans('agent.no_data_section') }}</td></tr>@endforelse</tbody></table></div>
                    <div class="am-card"><h4>{{ trans('agent.section_disk_speed') }}</h4>
                        <dl class="am-kv"><dt>{{ trans('agent.disk_read') }}</dt><dd>{{ $hb->disk_read_mbps ? number_format($hb->disk_read_mbps, 0, ',', '.') . ' MB/s' : $dash }}</dd>
                            <dt>{{ trans('agent.disk_write') }}</dt><dd>{{ $hb->disk_write_mbps ? number_format($hb->disk_write_mbps, 0, ',', '.') . ' MB/s' : $dash }}</dd></dl></div>
                </div>
            </div>

            {{-- JARINGAN & BATERAI --}}
            <div x-show="tab === 'network'" x-cloak class="am-grid2">
                <div class="am-card"><h4>{{ trans('agent.section_network') }}</h4>
                    <dl class="am-kv"><dt>{{ trans('agent.net_type') }}</dt><dd>{{ $hb->network_type ?: $dash }}</dd><dt>{{ trans('agent.ssid') }}</dt><dd>{{ $hb->wifi_ssid ?: $dash }}</dd>
                        <dt>{{ trans('agent.ip') }}</dt><dd>{{ $hb->ip_address ?: $dash }}</dd><dt>{{ trans('agent.mac') }}</dt><dd>{{ $hb->mac_address ?: $dash }}</dd><dt>{{ trans('agent.gateway') }}</dt><dd>{{ $hb->gateway ?: $dash }}</dd></dl></div>
                <div class="am-card"><h4>{{ trans('agent.section_battery') }}</h4>
                    @if ($hb->battery_present === false)<p class="am-muted" style="margin:0">Perangkat tanpa baterai (PC desktop).</p>
                    @elseif ($hb->battery_present === null)<p class="am-muted" style="margin:0">{{ trans('agent.no_data_section') }}</p>
                    @else<dl class="am-kv"><dt>{{ trans('agent.battery_level') }}</dt><dd>{{ $hb->battery_percent !== null ? $hb->battery_percent.'%' : $dash }} {{ $hb->battery_charging ? '⚡' : '' }}</dd>
                        <dt>{{ trans('agent.battery_health') }}</dt><dd>@if($hb->battery_health_percent !== null){{ $hb->battery_health_percent }}%<x-am.bar :value="$hb->battery_health_percent" :tone="$hb->battery_health_percent < 40 ? 'bad' : ($hb->battery_health_percent < 60 ? 'warn' : '')" />@else{{ $dash }}@endif</dd></dl>@endif</div>
            </div>

            {{-- KEAMANAN & UPDATE --}}
            <div x-show="tab === 'security'" x-cloak class="am-stack">
                <div class="am-grid2">
                    <div class="am-card"><h4>{{ trans('agent.section_compliance') }}</h4>
                        @php [$bl, $blc] = $hb->bitlocker_status === 'on' ? ['Aktif', 'ok'] : ($hb->bitlocker_status === 'off' ? ['Nonaktif', 'bad'] : ['Tidak diketahui', '']); [$df, $dfc] = $on($hb->defender_enabled); @endphp
                        <dl class="am-kv">
                            <dt>BitLocker</dt><dd><span class="am-pill {{ $blc }}">{{ $bl }}</span></dd>
                            <dt>Windows Defender</dt><dd><span class="am-pill {{ $dfc }}">{{ $df }}</span></dd>
                            <dt>{{ trans('agent.defender_signature') }}</dt><dd>{{ $hb->defender_signature_at?->format('d/m/Y H:i') ?? $dash }}</dd>
                            <dt>{{ trans('agent.last_patch') }}</dt><dd>{{ $hb->last_patch_at?->format('d/m/Y') ?? $dash }}</dd>
                            <dt>{{ trans('agent.sec_usb') }}</dt><dd>{{ ['blocked' => trans('agent.comp_usb_blocked'), 'write_blocked' => trans('agent.comp_usb_write'), 'allowed' => trans('agent.comp_usb_allowed')][$hb->usb_storage] ?? $dash }}</dd>
                            <dt>Administrator bawaan</dt><dd>{{ $hb->builtin_admin_enabled === null ? $dash : ($hb->builtin_admin_enabled ? 'Aktif' : 'Nonaktif') }}</dd>
                        </dl></div>
                    <div class="am-card"><h4>{{ trans('agent.sec_local_admins') }}</h4>
                        @forelse ($hb->local_admins ?? [] as $adm)
                            @php $ok = \App\Services\AgentAlertRules::isAllowedAdmin($adm['name']); @endphp
                            <div style="margin:4px 0;font-size:13px"><span class="am-pill {{ $ok ? 'ok' : 'warn' }}">{{ $ok ? 'diizinkan' : 'non-IT' }}</span>&nbsp; {{ $adm['name'] }} <span class="am-muted">{{ $adm['type'] ?? '' }}</span></div>
                        @empty<p class="am-muted" style="margin:0">{{ trans('agent.no_data_section') }}</p>@endforelse</div>
                </div>
                <div class="am-card"><h4>Windows Update</h4>
                    <dl class="am-kv"><dt>Perlu restart</dt><dd>{{ $hb->reboot_pending ? 'Ya' : ($hb->reboot_pending === false ? 'Tidak' : $dash) }}</dd>
                        <dt>Update tertunda</dt><dd>{{ $hb->pending_updates_count ?? $dash }}</dd><dt>Dicek</dt><dd>{{ $hb->updates_checked_at?->format('d/m/Y H:i') ?? $dash }}</dd></dl>
                    @if (! empty($hb->pending_updates))<ul style="margin:10px 0 0;padding-left:18px;font-size:13px">@foreach ($hb->pending_updates as $u)<li>{{ $u }}</li>@endforeach</ul>@endif</div>
            </div>

            {{-- SOFTWARE --}}
            <div x-show="tab === 'software'" x-cloak class="am-card" x-data="{ q: '' }">
                <div class="am-head"><h4 style="margin:0">{{ trans('agent.tab_software') }} ({{ $hb->software_count ?? 0 }})</h4>
                    <input type="search" x-model="q" placeholder="{{ trans('agent.software_search') }}" style="min-width:280px;padding:6px 10px;border:1px solid rgba(128,128,128,.4);border-radius:8px;background:transparent;color:inherit"></div>
                @if (empty($hb->software))<p class="am-muted" style="margin:0">{{ trans('agent.software_not_yet') }}</p>@else
                    <div class="am-scroll"><table class="am-table"><thead><tr><th>Nama</th><th>Versi</th><th>Penerbit</th></tr></thead><tbody>
                        @foreach ($hb->software as $sw)
                            <tr x-show="!q || @js(mb_strtolower(($sw['name'] ?? '').' '.($sw['publisher'] ?? ''))).includes(q.toLowerCase())"><td>{{ $sw['name'] }}</td><td>{{ $sw['version'] ?? $dash }}</td><td>{{ $sw['publisher'] ?? $dash }}</td></tr>
                        @endforeach</tbody></table></div>
                    @if($hb->software_reported_at)<p class="am-muted" style="margin:8px 0 0">Dilaporkan {{ $hb->software_reported_at->diffForHumans() }}</p>@endif
                @endif
            </div>

            {{-- AKSI / CHAT --}}
            <div x-show="tab === 'actions'" x-cloak class="am-stack">
                <div class="am-callout">{{ trans('agent.cmd_intro', ['hours' => 1]) }}<br><span class="am-muted">Tombol aksi (Kirim Perintah, Chat, Token) ada di kanan atas halaman ini.</span></div>
                <div class="am-card"><h4>{{ trans('agent.chat_title') }}</h4>
                    @if ($chatOpen)
                        <p class="am-muted" style="margin:0 0 8px">{{ trans('agent.chat_open_since', ['time' => $chatOpen->started_at?->diffForHumans()]) }}</p>
                        <div class="am-scroll" style="max-height:260px">
                            @forelse ($chatOpen->messages()->latest('id')->limit(40)->get()->reverse() as $m)
                                <p style="margin:6px 0;font-size:13px"><strong>{{ $m->sender === 'it' ? 'IT' : trans('agent.chat_user') }}:</strong> {{ $m->body }} <span class="am-muted">{{ $m->created_at?->format('H:i') }}</span></p>
                            @empty<p class="am-muted">{{ trans('agent.chat_waiting') }}</p>@endforelse
                        </div>
                    @else
                        <p class="am-muted" style="margin:0">{{ $tokenActive ? 'Tidak ada percakapan terbuka. Gunakan "Mulai Chat dengan Pengguna".' : trans('agent.chat_no_agent') }}</p>
                    @endif
                    @if ($chatHistory->count())<h4 style="margin-top:14px">{{ trans('agent.chat_history') }}</h4>
                        @foreach ($chatHistory as $c)<div class="am-muted" style="margin:3px 0">{{ $c->started_at?->format('d/m/Y H:i') }} · {{ trans('agent.chat_msgs', ['count' => $c->messages_count]) }} · {{ $c->close_reason === 'idle' ? trans('agent.chat_closed_by_idle') : trans('agent.chat_closed_by_admin') }}</div>@endforeach @endif</div>
                <div class="am-card"><h4>{{ trans('agent.cmd_history') }}</h4>
                    @if ($commands->isEmpty())<p class="am-muted" style="margin:0">{{ trans('agent.cmd_history_empty') }}</p>@else
                        <div class="am-scroll"><table class="am-table"><thead><tr><th>Waktu</th><th>{{ trans('agent.cmd_action') }}</th><th>Status</th><th>{{ trans('agent.cmd_requested_by') }}</th><th>{{ trans('agent.cmd_result') }}</th></tr></thead><tbody>
                            @foreach ($commands as $c)
                                <tr><td style="white-space:nowrap">{{ $c->requested_at?->format('d/m/Y H:i') }}</td><td>{{ str($c->type)->replace('_', ' ')->title() }}</td>
                                    <td><span class="am-pill {{ $cmdTone[$c->status] ?? '' }}">{{ trans('agent.cmd_status_'.$c->status) }}</span></td><td>{{ $c->requester?->name ?: $dash }}</td>
                                    <td style="max-width:420px;white-space:pre-wrap;word-break:break-word">{{ \Illuminate\Support\Str::limit((string) $c->result, 300) ?: $dash }}</td></tr>
                            @endforeach</tbody></table></div>@endif</div>
            </div>

            {{-- RIWAYAT PERUBAHAN --}}
            <div x-show="tab === 'changes'" x-cloak class="am-card"><h4>{{ trans('agent.tab_changes') }}</h4>
                <p class="am-muted" style="margin:0 0 10px">{{ trans('agent.changes_intro') }}</p>
                @if ($changes->isEmpty())<p class="am-muted" style="margin:0">{{ trans('agent.changes_empty') }}</p>@else
                    <div class="am-scroll" style="max-height:520px"><table class="am-table"><thead><tr><th>{{ trans('agent.when') }}</th><th>{{ trans('agent.category') }}</th><th>{{ trans('agent.item') }}</th><th>{{ trans('agent.before') }}</th><th>{{ trans('agent.after') }}</th></tr></thead><tbody>
                        @foreach ($changes as $c)<tr><td style="white-space:nowrap">{{ $c->changed_at?->format('d/m/Y H:i') }}</td><td>{{ $catLabel($c->category) }}</td><td>{{ $fieldLabel($c->field) }}</td>
                            <td style="word-break:break-word">{{ \Illuminate\Support\Str::limit((string) $c->old_value, 160) ?: $dash }}</td><td style="word-break:break-word">{{ \Illuminate\Support\Str::limit((string) $c->new_value, 160) ?: $dash }}</td></tr>@endforeach</tbody></table></div>
                    @if ($changesTotal > $changes->count())<p class="am-muted" style="margin:8px 0 0">{{ trans('agent.changes_limited', ['shown' => $changes->count(), 'total' => $changesTotal]) }}</p>@endif
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
