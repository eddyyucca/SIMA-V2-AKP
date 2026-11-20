<x-filament-widgets::widget>
    <x-am.styles />
    @php $dash = '—'; @endphp
    @if ($s['total'] === 0)
        <div class="am-callout">{{ trans('agent.monitor_empty') }}</div>
    @else
        <p class="am-muted" style="margin:0 0 10px">{{ trans('agent.monitor_subtitle') }}</p>
        <div class="am-kpis">
            <x-am.kpi :label="trans('agent.kpi_total')" :value="$s['total']" />
            <x-am.kpi :label="trans('agent.kpi_online')" :value="$s['online']" tone="ok" />
            <x-am.kpi :label="trans('agent.kpi_offline')" :value="$s['offline']" />
            <x-am.kpi :label="trans('agent.kpi_never')" :value="$s['never_reported']" tone="info" />
            <x-am.kpi :label="trans('agent.kpi_critical')" :value="$s['critical']" tone="bad" />
            <x-am.kpi :label="trans('agent.kpi_warning')" :value="$s['warning']" tone="warn" />
            <x-am.kpi :label="trans('agent.kpi_compliance')" :value="$s['compliance_percent'] !== null ? $s['compliance_percent'].'%' : $dash"
                :tone="($s['compliance_percent'] ?? 0) >= 90 ? 'ok' : (($s['compliance_percent'] ?? 0) >= 70 ? 'warn' : 'bad')"
                :sub="trans('agent.kpi_compliance_sub', ['ok' => $s['compliant'], 'total' => $s['total'] - $s['never_reported']])" />
        </div>
        @if ($truncated)<div class="am-callout">{{ trans('agent.monitor_truncated', ['max' => \App\Services\AgentFleetMonitor::MAX_DEVICES]) }}</div>@endif

        <details class="am-card" style="padding:10px 18px">
            <summary style="cursor:pointer;font-weight:600">{{ trans('agent.tab_distribution') }}</summary>
            <div class="am-grid2" style="grid-template-columns: repeat(4, minmax(0,1fr)); margin-top:12px">
                @foreach (['edition' => 'dist_edition', 'os' => 'dist_os', 'agent' => 'dist_agent', 'ram' => 'dist_ram'] as $key => $title)
                    @php $rows = $dist[$key] ?? []; $max = max(1, ...array_values($rows ?: [1])); @endphp
                    <div>
                        <h4 style="margin-bottom:8px">{{ trans('agent.'.$title) }}</h4>
                        @forelse ($rows as $label => $n)
                            <div class="am-row" style="margin:4px 0">
                                <span class="r-label" style="flex-basis:46%" title="{{ $label }}">{{ str_replace('Microsoft ', '', $label) }}</span>
                                <span class="r-bar"><x-am.bar :value="$n" :max="$max" :tone="$key === 'agent' && $latest && $label !== 'v'.$latest ? 'warn' : ''" /></span>
                                <span class="r-num" style="flex-basis:36px">{{ $n }}</span>
                            </div>
                        @empty
                            <p class="am-muted" style="margin:0">{{ trans('agent.dist_empty') }}</p>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </details>
    @endif
</x-filament-widgets::widget>
