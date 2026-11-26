<x-filament-panels::page>
    <x-am.styles />
    @php
        $pct = fn ($n) => $total > 0 ? round($n / $total * 100, 2) : 0;
        $dash = '—';
        $stateTone = ['token_only' => 'warn', 'missing' => ''];
        // grafik tren (garis hijau = terpasang, biru = online)
        $n = count($trend); $W = 640; $H = 200; $padL = 34; $padR = 12; $padT = 10; $padB = 22;
        $xs = fn ($i) => $n > 1 ? $padL + ($W - $padL - $padR) * $i / ($n - 1) : $padL + ($W - $padL - $padR) / 2;
        $ys = fn ($v) => $padT + ($H - $padT - $padB) * (1 - $v / 100);
        $line = function ($key) use ($trend, $xs, $ys) {
            $pts = [];
            foreach ($trend as $i => $p) $pts[] = round($xs($i), 1) . ',' . round($ys($p['total'] > 0 ? $p[$key] / $p['total'] * 100 : 0), 1);
            return implode(' ', $pts);
        };
    @endphp

    <p class="am-muted" style="margin:0 0 14px">{{ trans('agent.rollout_subtitle') }}</p>

    @if ($total === 0)
        <div class="am-callout">{{ trans('agent.rollout_empty', ['cats' => implode(', ', (array) config('agent.laptop_categories'))]) }}</div>
    @else
        <div class="am-grid2" style="grid-template-columns: 7fr 5fr; margin-bottom:16px">
            <div class="am-card">
                <div class="am-big">{{ number_format($percent_installed, 1) }}%</div>
                <div class="am-muted" style="margin-top:4px;font-size:13px">{{ trans('agent.rollout_hero') }} &middot; {{ $installed }} / {{ $total }}</div>
                <div class="am-seg">
                    <div style="width: {{ $pct($installed) }}%; background:#00a65a"></div>
                    <div style="width: {{ $pct($token_only) }}%; background:#f39c12"></div>
                </div>
                <div class="am-legend">
                    <span><span class="am-dot" style="background:#00a65a"></span>{{ trans('agent.rollout_installed') }}: <strong>{{ $installed }}</strong></span>
                    <span><span class="am-dot" style="background:#f39c12"></span>{{ trans('agent.rollout_token_only') }}: <strong>{{ $token_only }}</strong></span>
                    <span><span class="am-dot" style="background:#8a96a8"></span>{{ trans('agent.rollout_missing') }}: <strong>{{ $missing }}</strong></span>
                </div>
                @if ($pending_total === 0)<p style="margin:12px 0 0"><span class="am-pill ok">✓ {{ trans('agent.rollout_all_done') }}</span></p>@endif
            </div>
            <div class="am-kpis" style="margin-bottom:0; align-content:flex-start">
                <x-am.kpi :label="trans('agent.rollout_total')" :value="$total" />
                <x-am.kpi :label="trans('agent.rollout_installed')" :value="$installed" tone="ok" />
                <x-am.kpi :label="trans('agent.rollout_online')" :value="$online" tone="info" :sub="number_format($percent_online, 1).'%'" />
                <x-am.kpi :label="trans('agent.rollout_token_only')" :value="$token_only" tone="warn" />
                <x-am.kpi :label="trans('agent.rollout_missing')" :value="$missing" tone="bad" />
            </div>
        </div>

        <div class="am-card">
            <h4>{{ trans('agent.rollout_trend') }}</h4>
            <svg viewBox="0 0 {{ $W }} {{ $H }}" width="100%" style="max-height:220px" role="img" aria-label="{{ trans('agent.rollout_trend') }}">
                @foreach ([0, 25, 50, 75, 100] as $g)
                    <line x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ round($ys($g), 1) }}" y2="{{ round($ys($g), 1) }}" stroke="currentColor" stroke-opacity=".12" />
                    <text x="{{ $padL - 6 }}" y="{{ round($ys($g), 1) + 3 }}" text-anchor="end" font-size="9" fill="currentColor" fill-opacity=".6">{{ $g }}%</text>
                @endforeach
                @if ($n > 1)
                    <polyline points="{{ $line('online') }}" fill="none" stroke="#3c8dbc" stroke-width="2" stroke-linejoin="round" />
                    <polyline points="{{ $line('installed') }}" fill="none" stroke="#00a65a" stroke-width="2.5" stroke-linejoin="round" />
                @endif
                @foreach ($trend as $i => $p)
                    <circle cx="{{ round($xs($i), 1) }}" cy="{{ round($ys($p['total'] > 0 ? $p['installed'] / $p['total'] * 100 : 0), 1) }}" r="{{ $i === $n - 1 ? 4 : 2.2 }}" fill="#00a65a" />
                @endforeach
                @if ($n > 0)
                    <text x="{{ $padL }}" y="{{ $H - 6 }}" font-size="9" fill="currentColor" fill-opacity=".6">{{ $trend[0]['date'] }}</text>
                    <text x="{{ $W - $padR }}" y="{{ $H - 6 }}" text-anchor="end" font-size="9" fill="currentColor" fill-opacity=".6">{{ $trend[$n - 1]['date'] }}</text>
                @endif
            </svg>
            <p class="am-muted" style="margin:6px 0 0">{{ trans('agent.rollout_trend_note') }}</p>
        </div>

        <div class="am-grid2" style="margin-bottom:16px">
            @foreach (['rollout_by_department' => $by_department, 'rollout_by_section' => $by_section, 'rollout_by_location' => $by_location, 'rollout_by_status' => $by_status] as $title => $rows)
                <div class="am-card" style="margin-bottom:0">
                    <h4>{{ trans('agent.'.$title) }}</h4>
                    @foreach ($rows as $r)
                        <div class="am-row">
                            <span class="r-label" title="{{ $r['name'] }}">{{ $r['name'] }}</span>
                            <span class="r-bar"><x-am.bar :value="$r['percent']" /></span>
                            <span class="r-num">{{ $r['installed'] }} / {{ $r['total'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>

        @if ($pending_total > 0)
            <div class="am-card">
                <div class="am-head">
                    <h4 style="margin:0">{{ trans('agent.rollout_pending_title') }}</h4>
                    <a href="{{ route('agent.export.rollout') }}" class="am-btn">⤓ {{ trans('agent.rollout_csv') }}</a>
                </div>
                <p style="margin:8px 0 0">{{ $pending_total }} laptop belum terpasang / belum lapor kini digabung di tabel utama.
                    <a href="{{ \App\Filament\Resources\AgentMonitor\AgentMonitorResource::getUrl('index', ['tab' => 'belum']) }}" class="am-btn">Buka tabel Ikhtisar →</a></p>
            </div>
        @endif

        @if ($non_laptop_agents > 0)<p class="am-muted">{{ trans('agent.rollout_non_laptop', ['count' => $non_laptop_agents]) }}</p>@endif
    @endif
</x-filament-panels::page>
