<x-filament-panels::page>
    <x-am.styles />
    <style>
        .rp-tabs { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
        .rp-tab { flex: 1 1 200px; display: block; border: 1px solid rgba(128,128,128,.25); border-left-width: 5px; border-radius: 10px; padding: 12px 16px; color: inherit; text-decoration: none; }
        .rp-tab:hover { background: rgba(128,128,128,.07); }
        .rp-tab.active { box-shadow: 0 0 0 2px currentColor inset; }
        .rp-tab .t-name { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; font-weight: 700; }
        .rp-tab .t-num { font-size: 30px; font-weight: 700; line-height: 1.15; }
        .rp-tab.critical { border-left-color: #dd4b39; color: #b42318; } .rp-tab.warning { border-left-color: #f39c12; color: #9a5b00; } .rp-tab.info { border-left-color: #3c8dbc; color: #1d6b96; }
        .rp-tab .t-sub { font-size: 12px; opacity: .8; }
        .rp-chip { display: inline-block; margin: 2px 4px 2px 0; padding: 1px 8px; border-radius: 10px; background: rgba(128,128,128,.14); font-size: 12px; color: inherit; text-decoration: none; }
        .rp-chip:hover { background: rgba(128,128,128,.28); }
    </style>
    @php
        $tone = ['critical' => 'bad', 'warning' => 'warn', 'info' => 'info'];
        $groupList = $groups[$current] ?? [];
        $deviceUrl = fn ($a) => \App\Filament\Resources\AgentMonitor\AgentMonitorResource::getUrl('view', ['record' => $a]);
    @endphp

    <div class="am-head" style="margin-bottom:6px">
        <p class="am-muted" style="margin:0">{{ trans('agent.reports_subtitle') }}</p>
        <a href="{{ route('agent.export.alerts') }}" class="am-btn">⤓ {{ trans('agent.reports_export_all') }}</a>
    </div>

    <div class="rp-tabs">
        @foreach (['critical', 'warning', 'info'] as $sev)
            <a class="rp-tab {{ $sev }} {{ $current === $sev ? 'active' : '' }}" wire:click.prevent="$set('severity', '{{ $sev }}')" href="?tingkat={{ $sev }}">
                <div class="t-name">{{ trans('agent.sev_'.$sev) }}</div>
                <div class="t-num">{{ $summary[$sev]['devices'] }}</div>
                <div class="t-sub">{{ trans('agent.reports_devices', ['n' => $summary[$sev]['devices']]) }} &middot; {{ trans('agent.reports_types', ['n' => $summary[$sev]['types']]) }}</div>
            </a>
        @endforeach
    </div>

    <div class="am-card">
        <div class="am-head">
            <h4 style="margin:0"><span class="am-pill {{ $tone[$current] }}">{{ trans('agent.sev_'.$current) }}</span> &nbsp;{{ trans('agent.reports_items', ['n' => $summary[$current]['items']]) }}</h4>
            <a href="{{ route('agent.export.alerts', ['tingkat' => $current]) }}" class="am-btn">⤓ {{ trans('agent.reports_export_severity') }}</a>
        </div>

        @if (count($groupList) === 0)
            <div class="am-callout" style="margin:0;border-color:rgba(0,166,90,.4);background:rgba(0,166,90,.08)">{{ trans('agent.reports_none') }}</div>
        @else
            <div class="am-scroll" style="max-height:none"><table class="am-table">
                <thead><tr><th style="width:34%">{{ trans('agent.reports_type_col') }}</th><th style="width:90px;text-align:center">{{ trans('agent.reports_devices_col') }}</th><th>{{ trans('agent.col_asset') }}</th></tr></thead>
                <tbody>
                    @foreach ($groupList as $g)
                        @php $shown = array_slice($g['assets'], 0, 40); @endphp
                        <tr>
                            <td><strong>{{ trans('agent.alert_title_'.$g['code']) }}</strong></td>
                            <td style="text-align:center"><span class="am-pill">{{ count($g['assets']) }}</span></td>
                            <td>
                                @foreach ($shown as $asset)<a class="rp-chip" href="{{ $deviceUrl($asset) }}">{{ $asset->asset_tag }}</a>@endforeach
                                @if (count($g['assets']) > count($shown))<span class="am-muted">{{ trans('agent.reports_more', ['n' => count($g['assets']) - count($shown)]) }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</x-filament-panels::page>
