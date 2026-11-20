<x-filament-panels::page>
    <x-am.styles />
    @php
        $dash = '—';
        $pctOf = fn ($n, $t) => $t > 0 ? round($n / $t * 100) : 0;
        $manageable_ed = ['Pro', 'Enterprise', 'Education', 'Server'];
        $assetUrl = fn ($a) => \App\Filament\Resources\AgentMonitor\AgentMonitorResource::getUrl('view', ['record' => $a]);
    @endphp

    <p class="am-muted" style="margin:0 0 14px">{{ trans('agent.compliance_subtitle') }}</p>

    @if ($reporting === 0)
        <div class="am-callout">{{ trans('agent.monitor_empty') }}</div>
    @else
        <p class="am-muted" style="margin:0 0 12px">{{ trans('agent.comp_coverage', ['n' => $admin_data, 'total' => $reporting]) }}</p>

        <div class="am-kpis">
            <x-am.kpi :label="trans('agent.comp_manageable')" :value="$manageable" tone="ok" :sub="$pctOf($manageable, $reporting).'%'" />
            <x-am.kpi :label="trans('agent.comp_home')" :value="$home" :tone="$home > 0 ? 'bad' : 'ok'" :sub="$pctOf($home, $reporting).'%'" />
            <x-am.kpi :label="trans('agent.comp_admin_exposed')" :value="count($admin_exposed)" :tone="count($admin_exposed) > 0 ? 'warn' : 'ok'" />
            <x-am.kpi :label="trans('agent.comp_usb').': '.trans('agent.comp_usb_allowed')" :value="$usb['allowed']" :tone="$usb['allowed'] > 0 ? 'warn' : 'ok'" />
        </div>

        <div class="am-grid2" style="margin-bottom:16px">
            <div class="am-card">
                <h4>{{ trans('agent.comp_edition') }}</h4>
                @foreach ($edition as $name => $n)
                    <div class="am-row">
                        <span class="r-label">{{ $name === '-' ? trans('agent.comp_unknown') : 'Windows '.$name }}</span>
                        <span class="r-bar"><x-am.bar :value="$n" :max="$reporting" :tone="in_array($name, $manageable_ed, true) ? '' : ($name === 'Home' ? 'bad' : 'gray')" /></span>
                        <span class="r-num">{{ $n }}</span>
                    </div>
                @endforeach
                @if ($home > 0)<p class="am-muted" style="margin:10px 0 0">ⓘ {{ trans('agent.comp_home_note') }}</p>@endif
            </div>
            <div class="am-card">
                <h4>{{ trans('agent.comp_usb') }}</h4>
                @if ($usb_data === 0)
                    <p class="am-muted" style="margin:0">{{ trans('agent.comp_no_data') }}</p>
                @else
                    @foreach (['blocked' => ['comp_usb_blocked', ''], 'write_blocked' => ['comp_usb_write', 'info'], 'allowed' => ['comp_usb_allowed', 'warn'], 'unknown' => ['comp_usb_unknown', 'gray']] as $k => [$label, $tone])
                        <div class="am-row"><span class="r-label">{{ trans('agent.'.$label) }}</span><span class="r-bar"><x-am.bar :value="$usb[$k]" :max="$usb_data" :tone="$tone" /></span><span class="r-num">{{ $usb[$k] }}</span></div>
                    @endforeach
                @endif
                <p class="am-muted" style="margin:10px 0 0">{{ $require_usb_block ? trans('agent.comp_usb_note_on') : trans('agent.comp_usb_note_off') }}</p>
            </div>
            <div class="am-card">
                <h4>{{ trans('agent.comp_admin') }}</h4>
                @if ($admin_data === 0)
                    <p class="am-muted" style="margin:0">{{ trans('agent.comp_no_data') }}</p>
                @else
                    <div class="am-row"><span class="r-label">{{ trans('agent.comp_admin_clean') }}</span><span class="r-bar"><x-am.bar :value="$admin_clean" :max="$admin_data" /></span><span class="r-num">{{ $admin_clean }}</span></div>
                    <div class="am-row"><span class="r-label">{{ trans('agent.comp_admin_exposed') }}</span><span class="r-bar"><x-am.bar :value="count($admin_exposed)" :max="$admin_data" tone="warn" /></span><span class="r-num">{{ count($admin_exposed) }}</span></div>
                    <div class="am-row"><span class="r-label">{{ trans('agent.comp_builtin') }}</span><span class="r-bar"><x-am.bar :value="$builtin_admin_on" :max="$admin_data" tone="bad" /></span><span class="r-num">{{ $builtin_admin_on }}</span></div>
                @endif
                <p class="am-muted" style="margin:10px 0 0">{{ trans('agent.comp_admin_allowlist', ['list' => implode(', ', $allowlist) ?: $dash]) }}</p>
            </div>
            <div class="am-card">
                <h4>{{ trans('agent.comp_security') }}</h4>
                @foreach (['comp_bitlocker' => $bitlocker, 'comp_defender' => $defender] as $label => $data)
                    <div style="font-weight:600; margin:6px 0 2px">{{ trans('agent.'.$label) }}</div>
                    @foreach (['on' => ['comp_on', ''], 'off' => ['comp_off', 'bad'], 'unknown' => ['comp_unknown', 'gray']] as $k => [$l, $tone])
                        <div class="am-row"><span class="r-label">{{ trans('agent.'.$l) }}</span><span class="r-bar"><x-am.bar :value="$data[$k]" :max="$reporting" :tone="$tone" /></span><span class="r-num">{{ $data[$k] }}</span></div>
                    @endforeach
                @endforeach
                <div class="am-row" style="margin-top:10px"><span class="r-label">{{ trans('agent.comp_updates') }}</span><span class="r-bar"><x-am.bar :value="$pending_updates" :max="$reporting" tone="warn" /></span><span class="r-num">{{ $pending_updates }}</span></div>
            </div>
        </div>

        <div class="am-card">
            <h4>{{ trans('agent.comp_exposed_title') }} ({{ count($admin_exposed) }})</h4>
            @if (count($admin_exposed) === 0)
                <p class="am-muted" style="margin:0">{{ $admin_data === 0 ? trans('agent.comp_no_data') : trans('agent.comp_none_exposed') }}</p>
            @else
                <div class="am-scroll"><table class="am-table">
                    <thead><tr><th>{{ trans('agent.col_asset') }}</th><th>{{ trans('agent.col_user') }}</th><th>{{ trans('agent.col_hostname') }}</th><th>{{ trans('agent.comp_accounts') }}</th></tr></thead>
                    <tbody>
                        @foreach (array_slice($admin_exposed, 0, 200) as $e)
                            @php $d = $e['device']; @endphp
                            <tr>
                                <td><a href="{{ $assetUrl($d['asset']) }}">{{ $d['asset']->asset_tag }}</a></td>
                                <td>{{ $d['assigned'] ?: $dash }}</td>
                                <td>{{ $d['hb']->hostname ?: $dash }}</td>
                                <td>@foreach ($e['names'] as $name)<span class="am-pill warn" style="margin-right:4px">{{ $name }}</span>@endforeach</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>

        @if ($home > 0)
            <div class="am-card">
                <h4>{{ trans('agent.comp_home_title') }} ({{ $home }})</h4>
                <div class="am-scroll"><table class="am-table">
                    <thead><tr><th>{{ trans('agent.col_asset') }}</th><th>{{ trans('agent.col_user') }}</th><th>{{ trans('agent.col_hostname') }}</th><th>{{ trans('agent.comp_edition_col') }}</th></tr></thead>
                    <tbody>
                        @foreach (array_slice($home_devices, 0, 200) as $d)
                            <tr>
                                <td><a href="{{ $assetUrl($d['asset']) }}">{{ $d['asset']->asset_tag }}</a></td>
                                <td>{{ $d['assigned'] ?: $dash }}</td>
                                <td>{{ $d['hb']->hostname ?: $dash }}</td>
                                <td><span class="am-pill bad">{{ trim(str_replace('Microsoft ', '', (string) $d['hb']->os_version)) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            </div>
        @endif
    @endif
</x-filament-panels::page>
