@php
    $d = $this->data();
    $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $rpShort = function ($v) {
        $v = (float) $v;
        return $v >= 1e9 ? 'Rp ' . round($v / 1e9, 1) . ' M' : ($v >= 1e6 ? 'Rp ' . round($v / 1e6, 1) . ' jt' : 'Rp ' . number_format($v, 0, ',', '.'));
    };
    $hour = (int) now()->format('H');
    $greet = $hour < 11 ? 'Selamat pagi' : ($hour < 15 ? 'Selamat siang' : ($hour < 18 ? 'Selamat sore' : 'Selamat malam'));
    $palette = ['#1f6fb5', '#29aae1', '#2fa99b', '#10264a', '#6aa6d8', '#7cc7c0', '#94a3b8', '#2f6ab5'];

    $spark = function (array $vals, string $color) {
        $n = count($vals); $max = max(max($vals), 1); $pts = [];
        foreach ($vals as $i => $v) { $pts[] = round($i / max($n - 1, 1) * 100, 1) . ',' . round(30 - ($v / $max) * 26 - 2, 1); }
        $line = implode(' ', $pts);
        return '<svg viewBox="0 0 100 30" preserveAspectRatio="none" class="pd-spark"><defs><linearGradient id="g' . md5($color . $line) . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $color . '" stop-opacity=".35"/><stop offset="1" stop-color="' . $color . '" stop-opacity="0"/></linearGradient></defs><polygon points="0,30 ' . $line . ' 100,30" fill="url(#g' . md5($color . $line) . ')"/><polyline points="' . $line . '" fill="none" stroke="' . $color . '" stroke-width="1.6" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    };

    $icons = [
        'box' => 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
        'user' => 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.1a7.5 7.5 0 0115 0',
        'check' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'wrench' => 'M11.42 15.17L17.25 21A2.65 2.65 0 0021 17.25l-5.88-5.88m-3.7 3.8l-4.6 4.6a2.12 2.12 0 01-3-3l4.6-4.6m3-3l-.4-.4a4.5 4.5 0 116.4-6.4l-3.6 3.6.4 2.4 2.4.4 3.6-3.6a4.5 4.5 0 01-6.4 6.4z',
        'alert' => 'M12 9v3.75m-9.3 2.9c-.9 1.5.2 3.35 1.95 3.35h14.7c1.75 0 2.85-1.85 1.95-3.35L13.95 4.1c-.9-1.5-3-1.5-3.9 0L2.7 15.65zM12 15.75h.01',
        'coin' => 'M12 6v12m-3-2.8c.5.9 1.6 1.3 3 1.3 1.7 0 3-.8 3-2s-1.3-1.7-3-2-3-.8-3-2 1.3-2 3-2c1.4 0 2.5.4 3 1.3M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'qr' => 'M3.75 4.5h5.25v5.25H3.75zM15 4.5h5.25v5.25H15zM3.75 15h5.25v5.25H3.75zM15 15h2.25v2.25H15zM18 18h2.25v2.25H18zM15 19.5h.01M19.5 15h.01',
        'plus' => 'M12 4.5v15m7.5-7.5h-15',
        'doc' => 'M19.5 14.25v-2.6a3.37 3.37 0 00-3.38-3.4h-1.5A1.88 1.88 0 0112.75 6.4v-1.5A3.37 3.37 0 009.4 1.5H8.25M10.5 1.5H5.62c-.62 0-1.12.5-1.12 1.13v17.25c0 .62.5 1.12 1.12 1.12h12.75c.62 0 1.12-.5 1.12-1.12V10.5A9 9 0 0010.5 1.5z',
        'out' => 'M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75',
    ];
    $svg = fn ($k) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="' . $icons[$k] . '"/></svg>';
    $actIcon = ['checkout' => ['out', '#1f6fb5'], 'checkin' => ['check', '#2fa99b'], 'transfer' => ['user', '#29aae1'], 'stock' => ['box', '#10264a']];

    $catTotal = max($d['byCategory']->sum(), 1);
    $locMax = max($d['byLocation']->max() ?? 1, 1);
    $alertCount = $d['lowStock']->count() + $d['licenses']->count() + $d['warranty']->count() + $d['lateRepairs']->count() + $d['resignedHolding']->count() + $d['overSeats']->count() + $d['rentalExpiring']->count() + $d['arrivalsAtRisk']->count();
@endphp

<x-filament-panels::page>
<style>
    .pd{--bg:#fff;--card:#fff;--ink:#0f172a;--mute:#64748b;--line:rgba(15,23,42,.08);--soft:rgba(15,23,42,.04);--shadow:0 1px 2px rgba(15,23,42,.04),0 8px 24px -8px rgba(15,23,42,.10);color:var(--ink);font-feature-settings:"tnum"}
    .dark .pd{--card:#111827;--ink:#f1f5f9;--mute:#94a3b8;--line:rgba(255,255,255,.08);--soft:rgba(255,255,255,.04);--shadow:0 1px 2px rgba(0,0,0,.3),0 8px 24px -8px rgba(0,0,0,.5)}
    .pd *{box-sizing:border-box}
    .pd-hero{position:relative;overflow:hidden;border-radius:22px;padding:44px 48px;color:#fff;background:radial-gradient(1200px 400px at 90% -20%,rgba(55,186,171,.5),transparent 60%),radial-gradient(800px 360px at -10% 120%,rgba(41,170,225,.5),transparent 60%),linear-gradient(135deg,#0b1a3a,#183068 55%,#1f4f93);box-shadow:0 20px 40px -18px rgba(30,27,75,.6)}
    .pd-hero::after{content:"";position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.07) 1px,transparent 1px);background-size:18px 18px;mask-image:linear-gradient(90deg,transparent,#000 70%);pointer-events:none}
    .pd-hero h1{font-size:2.6rem;font-weight:800;letter-spacing:-.02em;line-height:1.15;margin:0}
    .pd-hero p{margin:6px 0 0;color:rgba(255,255,255,.72);font-size:1.1rem}
    .pd-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:99px;background:rgba(255,255,255,.12);backdrop-filter:blur(6px);font-size:.75rem;font-weight:600;border:1px solid rgba(255,255,255,.16);margin-bottom:12px}
    .pd-pill i{width:7px;height:7px;border-radius:50%;background:#34d399;box-shadow:0 0 0 4px rgba(52,211,153,.25)}
    .pd-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:20px;position:relative;z-index:1}
    .pd-btn{display:inline-flex;align-items:center;gap:8px;padding:13px 22px;border-radius:12px;font-weight:600;font-size:1rem;text-decoration:none;transition:.18s;border:1px solid rgba(255,255,255,.18);color:#fff;background:rgba(255,255,255,.1);backdrop-filter:blur(6px)}
    .pd-btn:hover{background:rgba(255,255,255,.2);transform:translateY(-1px)}
    .pd-btn.primary{background:linear-gradient(135deg,#29aae1,#2f6ab5);color:#fff;border-color:transparent;box-shadow:0 8px 20px -6px rgba(41,170,225,.7)}
    .pd-btn.primary:hover{filter:brightness(1.06)}
    .pd-btn svg{width:20px;height:20px}
    .pd-grid{display:grid;gap:24px;margin-top:24px}
    .pd-kpis{grid-template-columns:repeat(6,minmax(0,1fr))}
    @media(max-width:1300px){.pd-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
    @media(max-width:640px){.pd-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.pd-kpi .pd-val{font-size:1.5rem}}
    .pd-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:24px;box-shadow:var(--shadow);position:relative;overflow:hidden;transition:.2s}
    .pd-card.hover:hover{transform:translateY(-3px);box-shadow:0 18px 36px -14px rgba(15,23,42,.25)}
    .pd-kpi{padding:20px 22px}.pd-kpi .top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}.pd-kpi .pd-label{min-width:0;line-height:1.35}
    .pd-ico{flex:none;width:44px;height:44px;border-radius:12px;display:grid;place-items:center;color:#fff;box-shadow:0 6px 14px -6px rgba(31,111,181,.6)}
    .pd-ico svg{width:22px;height:22px}
    .pd-label{font-size:.74rem;font-weight:600;color:var(--mute);letter-spacing:.02em;text-transform:uppercase}
    .pd-val{font-size:2.1rem;white-space:nowrap;font-weight:800;letter-spacing:-.03em;line-height:1.1;margin-top:10px}
    .pd-val small{font-size:.9rem;font-weight:600;color:var(--mute);margin-left:4px}
    .pd-sub{font-size:.9rem;color:var(--mute);margin-top:2px}
    .pd-spark{width:100%;height:52px;margin-top:10px;display:block}
    .pd-badge{display:inline-block;white-space:nowrap;margin:3px 4px 0 0;font-size:.7rem;font-weight:700;padding:3px 9px;border-radius:99px}
    .pd-h{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
    .pd-h h3{font-size:1.2rem;font-weight:700;margin:0;letter-spacing:-.01em}
    .pd-h span{font-size:.85rem;color:var(--mute)}
    .pd-cols{grid-template-columns:repeat(12,1fr)}
    .pd-c4{grid-column:span 4}.pd-c5{grid-column:span 5}.pd-c6{grid-column:span 6}.pd-c7{grid-column:span 7}.pd-c8{grid-column:span 8}.pd-c12{grid-column:span 12}
    @media(max-width:1100px){.pd-c4,.pd-c5,.pd-c6,.pd-c7,.pd-c8{grid-column:span 12}}
    .pd-donut{display:flex;align-items:center;gap:22px;flex-wrap:wrap}
    .pd-donut svg{width:230px;height:230px;transform:rotate(-90deg);flex:none}
    .pd-donut .c{position:absolute;width:230px;text-align:center;pointer-events:none}
    .pd-legend{flex:1;min-width:150px;display:grid;gap:8px}
    .pd-legend div{display:flex;align-items:center;gap:8px;font-size:1rem}
    .pd-legend i{width:10px;height:10px;border-radius:3px;flex:none}
    .pd-legend b{margin-left:auto;font-variant-numeric:tabular-nums}
    .pd-bar{margin-bottom:12px}
    .pd-bar .r{display:flex;justify-content:space-between;font-size:1rem;margin-bottom:5px}
    .pd-bar .t{height:12px;border-radius:99px;background:var(--soft);overflow:hidden}
    .pd-bar .t span{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#29aae1,#37baab)}
    .pd-ring{display:grid;place-items:center;position:relative;margin:4px auto 10px;width:210px;height:210px}
    .pd-ring svg{transform:rotate(-90deg);width:210px;height:210px}
    .pd-ring .c{position:absolute;text-align:center}
    .pd-ring .c b{font-size:2.6rem;font-weight:800;letter-spacing:-.03em;display:block;line-height:1}
    .pd-stack{display:flex;height:12px;border-radius:99px;overflow:hidden;background:var(--soft);margin:6px 0 10px}
    .pd-stack span{display:block;height:100%}
    .pd-cols2{display:grid;grid-template-columns:repeat(2,1fr);gap:8px 14px;font-size:.95rem}
    .pd-cols2 div{display:flex;align-items:center;gap:7px}.pd-cols2 i{width:8px;height:8px;border-radius:50%}.pd-cols2 b{margin-left:auto}
    .pd-mini{display:flex;align-items:flex-end;gap:10px;height:210px;padding-top:6px}
    .pd-mini div{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;gap:6px}
    .pd-mini span{display:block;width:100%;border-radius:8px 8px 3px 3px;background:linear-gradient(180deg,#1f6fb5,#6aa6d8);min-height:3px;transition:.2s}
    .pd-mini div:hover span{filter:brightness(1.15)}
    .pd-mini small{font-size:.7rem;color:var(--mute)}
    .pd-mini em{font-style:normal;font-size:.68rem;color:var(--mute);font-weight:600}
    .pd-list{display:grid;gap:2px;max-height:520px;overflow:auto;margin:0 -6px}
    .pd-item{display:flex;gap:12px;align-items:center;padding:9px 6px;border-radius:12px;text-decoration:none;color:inherit}
    a.pd-item:hover{background:var(--soft)}
    .pd-item .ic{width:44px;height:44px;border-radius:10px;display:grid;place-items:center;flex:none}
    .pd-item .ic svg{width:22px;height:22px}
    .pd-item .tx{flex:1;min-width:0}
    .pd-item .tx b{display:block;font-size:1rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .pd-item .tx span{font-size:.88rem;color:var(--mute)}
    .pd-item time{font-size:.72rem;color:var(--mute);white-space:nowrap}
    .pd-tabs{display:grid;gap:14px}
    .pd-al h4{font-size:.82rem;text-transform:uppercase;letter-spacing:.05em;color:var(--mute);margin:0 0 6px;display:flex;align-items:center;gap:8px}
    .pd-al h4 em{font-style:normal;background:#d64545;color:#fff;border-radius:99px;padding:1px 7px;font-size:.68rem}
    .pd-al .row{display:flex;justify-content:space-between;gap:8px;font-size:.95rem;padding:6px 10px;border-radius:10px;background:var(--soft);margin-bottom:4px}
    .pd-al .row span:last-child{font-weight:700;white-space:nowrap}
    .pd-ok{display:flex;gap:12px;align-items:center;padding:18px;border-radius:14px;background:linear-gradient(135deg,rgba(47,169,155,.14),rgba(47,169,155,.04));color:#17766b;font-weight:600}
    .pd-ok svg{width:28px;height:28px}
    .pd-empty{color:var(--mute);font-size:.85rem;padding:10px 0}
    .pd{--sp1:#1f6fb5;--sp2:#2fa99b}
    .dark .pd{--sp1:#3987e5;--sp2:#37baab}
    .pd-sp-tiles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}
    .pd-sp-tile{border:1px solid var(--line);border-radius:14px;padding:14px 16px}
    .pd-sp-tile .k{display:flex;align-items:center;gap:8px;font-size:.78rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--mute)}
    .pd-sp-tile .k i{width:10px;height:10px;border-radius:3px;display:inline-block}
    .pd-sp-tile .v{font-size:1.6rem;font-weight:800;letter-spacing:-.02em;margin-top:6px}
    .pd-sp-tile .d{font-size:.82rem;color:var(--mute);margin-top:2px}
    .pd-sp-legend{display:flex;gap:18px;flex-wrap:wrap;font-size:.85rem;color:var(--mute);margin:0 0 10px}
    .pd-sp-legend span{display:inline-flex;align-items:center;gap:7px}
    .pd-sp-legend i{width:10px;height:10px;border-radius:3px;display:inline-block}
    .pd-sp-chart{display:grid;grid-template-columns:48px 1fr;gap:0 8px;padding-right:44px}
    .pd-sp-y{position:relative;height:210px}
    .pd-sp-y span{position:absolute;right:0;transform:translateY(50%);font-size:.7rem;color:var(--mute);white-space:nowrap}
    .pd-sp-plot{position:relative;height:210px}
    .pd-sp-grid{position:absolute;left:0;right:0;height:1px;background:var(--line)}
    .pd-sp-cols{position:absolute;inset:0;display:flex}
    .pd-sp-g{flex:1;height:100%;position:relative;display:flex;align-items:flex-end;justify-content:center;gap:2px;border-radius:8px;outline:none}
    .pd-sp-g:hover,.pd-sp-g:focus-visible{background:var(--soft)}
    .pd-sp-b{flex:1;max-width:24px;border-radius:4px 4px 0 0;min-height:0}
    .pd-sp-b.c{background:var(--sp1)}.pd-sp-b.o{background:var(--sp2)}
    .pd-sp-lbl{position:absolute;font-size:.7rem;font-weight:700;white-space:nowrap;color:var(--ink);pointer-events:none}
    .pd-sp-lbl.c{right:50%;margin-right:2px}.pd-sp-lbl.o{left:50%;margin-left:2px}
    .pd-sp-m{display:flex;grid-column:2;margin-top:6px}
    .pd-sp-m span{flex:1;text-align:center;font-size:.72rem;color:var(--mute)}
    .pd-sp-tip{display:none;position:absolute;top:6px;left:50%;transform:translateX(-50%);z-index:6;min-width:190px;background:var(--card);border:1px solid var(--line);border-radius:10px;box-shadow:var(--shadow);padding:10px 12px;font-size:.8rem;pointer-events:none}
    .pd-sp-g.l .pd-sp-tip{left:0;transform:none}.pd-sp-g.r .pd-sp-tip{left:auto;right:0;transform:none}
    .pd-sp-g:hover .pd-sp-tip,.pd-sp-g:focus-visible .pd-sp-tip{display:block}
    .pd-sp-tip b{display:block;margin-bottom:6px}
    .pd-sp-tip div{display:flex;justify-content:space-between;gap:14px;align-items:center}
    .pd-sp-tip i{width:9px;height:9px;border-radius:3px;display:inline-block;margin-right:6px}
    .pd-sp-brk{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px;margin-top:22px}
    .pd-sp-brk h4{font-size:.8rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--mute);margin:0 0 8px;display:flex;align-items:center;gap:8px}
    .pd-sp-brk h4 i{width:10px;height:10px;border-radius:3px;display:inline-block}
    .pd-sp-line{padding:7px 0;border-top:1px solid var(--line);font-size:.9rem}
    .pd-sp-line .r{display:flex;justify-content:space-between;gap:12px}
    .pd-sp-line .t{height:6px;border-radius:3px;background:var(--soft);margin-top:6px;overflow:hidden}
    .pd-sp-line .t span{display:block;height:100%;border-radius:3px}
    .pd-sp-tbl{width:100%;border-collapse:collapse;font-size:.85rem;margin-top:8px}
    .pd-sp-tbl th,.pd-sp-tbl td{padding:6px 10px;border-top:1px solid var(--line);text-align:right}
    .pd-sp-tbl th:first-child,.pd-sp-tbl td:first-child{text-align:left}
    @media(max-width:760px){.pd-sp-tiles,.pd-sp-brk{grid-template-columns:1fr}.pd-sp-chart{padding-right:10px;grid-template-columns:40px 1fr}.pd-sp-lbl{display:none}}
    .pd-ctl{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .pd-ctl select{border:1px solid var(--line);border-radius:10px;padding:7px 12px;background:var(--card);color:var(--ink);font-size:.9rem;font-weight:600}
    .pd-dept{width:100%;border-collapse:separate;border-spacing:0;font-size:.95rem}
    .pd-dept th{background:var(--soft);color:var(--mute);font-size:.74rem;letter-spacing:.06em;text-transform:uppercase;padding:12px 16px;text-align:left}
    .pd-dept th.c,.pd-dept td.c{text-align:center}.pd-dept th.r,.pd-dept td.r{text-align:right}
    .pd-dept td{padding:12px 16px;border-top:1px solid var(--line);font-weight:700;text-transform:uppercase;letter-spacing:.01em}
    .pd-dept td.n{border-left:5px solid var(--dc)}
    .pd-dept td.n i{display:inline-block;width:12px;height:12px;border-radius:50%;background:var(--dc);margin-right:12px;vertical-align:-1px}
    .pd-dept tr:nth-child(even) td{background:var(--soft)}
    .pd-dept tfoot td{border-top:2px solid var(--line);background:transparent!important}
    .pd-dept tfoot td.r{text-transform:none}
    .pd-cnt{display:inline-block;min-width:30px;padding:2px 9px;border-radius:99px;background:#e8f1fa;border:1px solid #cfe2f4;color:#195f9d;font-size:.78rem;font-weight:700;text-align:center}
    .pd-cnt-total{background:#1f6fb5;border-color:#1f6fb5;color:#fff;font-size:.82rem}
    .pd-val2{display:inline-block;padding:5px 12px;border-radius:8px;color:#fff;font-size:.82rem;font-weight:700;text-transform:none}
    .pd-val2{background:#eef3fa!important;color:#10264a!important;border:1px solid #dbe5f2;font-weight:650!important}.pd-dept td.n i{background:#6aa6d8!important;box-shadow:none!important}.pd-dept td.n{border-left:3px solid #dbe5f2!important}.pd-dept td{text-transform:none!important}
    @media(max-width:640px){.pd-dept th,.pd-dept td{padding:10px 8px;font-size:.8rem}}
    @media(max-width:640px){.pd-hero{padding:22px 18px}.pd-hero h1{font-size:1.4rem}.pd-val{font-size:1.7rem}}
</style>

<div class="pd">
    {{-- HERO --}}
    <section class="pd-hero">
        <span class="pd-pill"><i></i> {{ now()->translatedFormat('l, d F Y') }} · WITA</span>
        <h1>{{ $greet }}, {{ \Illuminate\Support\Str::of($d['user']->name)->explode(' ')->first() }}</h1>
        <p>
            @if($alertCount)
                Ada <b style="color:#bfe6f5">{{ $alertCount }} hal</b> yang perlu perhatian hari ini.
            @else
                Semua aman. Tidak ada peringatan hari ini.
            @endif
            Mengelola <b>{{ number_format($d['total']) }}</b> aset senilai <b>{{ $rpShort($d['value']) }}</b>.
        </p>
        <div class="pd-actions">
            <a class="pd-btn primary" href="{{ \App\Filament\Pages\ScanPage::getUrl() }}">{!! $svg('qr') !!} Scan QR</a>
            @if($d['canManage'])
                <a class="pd-btn" href="{{ \App\Filament\Resources\Assets\AssetResource::getUrl('create') }}">{!! $svg('plus') !!} Tambah Aset</a>
                <a class="pd-btn" href="{{ \App\Filament\Resources\Assets\AssetResource::getUrl() }}">{!! $svg('out') !!} Serah Terima</a>
                <a class="pd-btn" href="{{ \App\Filament\Pages\Offboarding::getUrl() }}">{!! $svg('user') !!} Offboarding</a>
            @endif
            <a class="pd-btn" href="{{ \App\Filament\Resources\RentalAssets\RentalAssetResource::getUrl() }}">{!! $svg('doc') !!} Aset Sewa</a>
            <a class="pd-btn" href="{{ \App\Filament\Resources\ArrivalPlans\ArrivalPlanResource::getUrl() }}">{!! $svg('user') !!} Rencana Kedatangan</a>
            @can('view reports')
                <a class="pd-btn" href="{{ \App\Filament\Pages\Reports::getUrl() }}">{!! $svg('doc') !!} Laporan</a>
            @endcan
        </div>
    </section>

    {{-- KPI --}}
    <section class="pd-grid pd-kpis">
        @php
            $kpis = [
                ['Total aset', $d['total'], 'aset aktif', 'box', 'linear-gradient(135deg,#1f6fb5,#29aae1)', '#29aae1', $d['added'], 'ditambah / bln'],
                ['Dipakai', $d['deployed'], $d['util'] . '% utilisasi', 'user', 'linear-gradient(135deg,#1f6fb5,#29aae1)', '#1f6fb5', $d['moves'], 'stok keluar / bln'],
                ['Tersedia', $d['available'], 'siap diserahkan', 'check', 'linear-gradient(135deg,#1f6fb5,#29aae1)', '#2fa99b', $d['added'], null],
                ['Dalam perbaikan', $d['repair'], $d['openRepairs'] . ' tiket terbuka', 'wrench', 'linear-gradient(135deg,#1f6fb5,#29aae1)', '#d99a2b', $d['repairCost'], 'biaya / bln'],
            ];
        @endphp
        @foreach($kpis as [$label, $val, $sub, $ic, $grad, $col, $series, $note])
            <div class="pd-card hover pd-kpi">
                <div class="top">
                    <div><div class="pd-label">{{ $label }}</div></div>
                    <div class="pd-ico" style="background:{{ $grad }}">{!! $svg($ic) !!}</div>
                </div>
                <div class="pd-val">{{ number_format($val) }}</div>
                <div class="pd-sub">{{ $sub }}</div>
                {!! $spark($series, $col) !!}
            </div>
        @endforeach
        <div class="pd-card hover pd-kpi">
            <div class="top">
                <div class="pd-label">Stok ≤ minimum</div>
                <div class="pd-ico" style="background:{{ $d['lowStock']->count() ? 'linear-gradient(135deg,#d64545,#e25555)' : 'linear-gradient(135deg,#1f6fb5,#29aae1)' }}">{!! $svg('alert') !!}</div>
            </div>
            <div class="pd-val">{{ $d['lowStock']->count() }}<small>item</small></div>
            <div class="pd-sub">{{ $d['lowStock']->count() ? 'perlu dibeli segera' : 'stok aman' }}</div>
            <div style="margin-top:12px">
                <span class="pd-badge" style="background:{{ $d['lowStock']->count() ? 'rgba(214,69,69,.10);color:#b13a3a' : 'rgba(47,169,155,.12);color:#17766b' }}">{{ $d['lowStock']->count() ? 'Perlu tindakan' : 'Terkendali' }}</span>
            </div>
        </div>
        <div class="pd-card hover pd-kpi">
            <div class="top">
                <div class="pd-label">Nilai buku aset</div>
                <div class="pd-ico" style="background:linear-gradient(135deg,#29aae1,#29aae1)">{!! $svg('coin') !!}</div>
            </div>
            <div class="pd-val" style="font-size:1.45rem;margin-top:14px">{{ $rpShort($d['book']) }}</div>
            <div class="pd-sub">perolehan {{ $rpShort($d['depCost']) }} · susut {{ now()->year }}: {{ $rpShort($d['depYear']) }}</div>
            <div style="margin-top:12px"><span class="pd-badge" style="background:rgba(31,111,181,.10);color:#195f9d">{{ $d['lost'] }} hilang · {{ $d['borrowed'] }} dipinjam</span>
                <span class="pd-badge" style="background:rgba(31,111,181,.10);color:#195f9d;margin-left:4px">{{ $d['rentalCount'] }} sewa · {{ $rpShort($d['rentalMonthly']) }}/bln</span></div>
        </div>
    </section>

    {{-- CHARTS --}}
    <section class="pd-grid pd-cols">
        <div class="pd-card pd-c5">
            <div class="pd-h"><h3>Aset per kategori</h3><span>{{ $d['byCategory']->count() }} kategori</span></div>
            <div class="pd-donut">
                <div style="position:relative;display:grid;place-items:center">
                    <svg viewBox="0 0 42 42">
                        <circle cx="21" cy="21" r="15.915" fill="none" stroke="var(--soft)" stroke-width="5"/>
                        @php $off = 0; @endphp
                        @foreach($d['byCategory'] as $name => $n)
                            @php $pct = $n / $catTotal * 100; @endphp
                            <circle cx="21" cy="21" r="15.915" fill="none" stroke="{{ $palette[$loop->index % count($palette)] }}" stroke-width="5"
                                    stroke-dasharray="{{ max($pct - 0.8, 0.1) }} {{ 100 - max($pct - 0.8, 0.1) }}" stroke-dashoffset="{{ -$off }}" stroke-linecap="butt"/>
                            @php $off += $pct; @endphp
                        @endforeach
                    </svg>
                    <div class="c"><b style="font-size:2.6rem;font-weight:800;letter-spacing:-.03em;display:block;line-height:1">{{ $d['total'] }}</b><span style="font-size:.72rem;color:var(--mute)">total aset</span></div>
                </div>
                <div class="pd-legend">
                    @forelse($d['byCategory'] as $name => $n)
                        <div><i style="background:{{ $palette[$loop->index % count($palette)] }}"></i>{{ $name }}<b>{{ $n }}</b></div>
                    @empty <div class="pd-empty">Belum ada aset</div> @endforelse
                </div>
            </div>
        </div>

        <div class="pd-card pd-c4">
            <div class="pd-h"><h3>Utilisasi aset</h3><span>dipakai / total</span></div>
            <div class="pd-ring">
                <svg viewBox="0 0 42 42">
                    <circle cx="21" cy="21" r="16" fill="none" stroke="var(--soft)" stroke-width="4.5"/>
                    <circle cx="21" cy="21" r="16" fill="none" stroke="url(#ug)" stroke-width="4.5" stroke-linecap="round" stroke-dasharray="{{ $d['util'] * 1.0053 }} 100.53"/>
                    <defs><linearGradient id="ug" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1f6fb5"/><stop offset="1" stop-color="#29aae1"/></linearGradient></defs>
                </svg>
                <div class="c"><b>{{ $d['util'] }}%</b><span style="font-size:.72rem;color:var(--mute)">terpakai</span></div>
            </div>
            @php
                $seg = [['Dipakai', $d['deployed'], '#1f6fb5'], ['Tersedia', $d['available'], '#2fa99b'], ['Perbaikan', $d['repair'], '#d99a2b'], ['Dipinjam', $d['borrowed'], '#29aae1'], ['Hilang', $d['lost'], '#64748b']];
                $segTotal = max(array_sum(array_column($seg, 1)), 1);
            @endphp
            <div class="pd-stack">@foreach($seg as [$l, $v, $c])<span style="width:{{ $v / $segTotal * 100 }}%;background:{{ $c }}"></span>@endforeach</div>
            <div class="pd-cols2">@foreach($seg as [$l, $v, $c])<div><i style="background:{{ $c }}"></i>{{ $l }}<b>{{ $v }}</b></div>@endforeach</div>
        </div>

        <div class="pd-card pd-c3" style="grid-column:span 3">
            <div class="pd-h"><h3>Biaya perbaikan</h3><span>6 bulan</span></div>
            @php $mx = max(max($d['repairCost']), 1); @endphp
            <div class="pd-mini">
                @foreach($d['repairCost'] as $i => $v)
                    <div title="{{ $rp($v) }}"><em>{{ $v ? $rpShort($v) : '' }}</em><span style="height:{{ max($v / $mx * 100, 3) }}%"></span><small>{{ $d['labels'][$i] }}</small></div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ASET PER DEPARTMENT --}}
    @php $dp = $this->departmentData(); @endphp
    <section class="pd-grid">
        <div class="pd-card" id="aset-per-department">
            <div class="pd-h" style="flex-wrap:wrap;gap:12px">
                <h3>Aset per Department</h3>
                <div class="pd-ctl">
                    <select wire:model.live="deptCategory" aria-label="Kategori aset">
                        <option value="">Semua kategori aset</option>
                        @foreach($dp['categories'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    <select wire:model.live="deptValue" aria-label="Jenis nilai">
                        <option value="purchase">Nilai perolehan</option>
                        <option value="book">Nilai buku</option>
                    </select>
                </div>
            </div>
            <div style="overflow-x:auto;border:1px solid var(--line);border-radius:12px">
                <table class="pd-dept">
                    <thead><tr><th>Department</th><th class="c">Aset</th><th class="r">{{ $this->deptValue === 'book' ? 'Nilai buku' : 'Nilai perolehan' }}</th></tr></thead>
                    <tbody>
                    @forelse($dp['rows'] as $r)
                        <tr>
                            <td class="n" style="--dc:{{ $r['color'] }}"><i></i>{{ $r['name'] }}</td>
                            <td class="c"><span class="pd-cnt">{{ number_format($r['count']) }}</span></td>
                            <td class="r"><span class="pd-val2 {{ $r['tone'] }}">{{ $rp($r['value']) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" style="text-align:center;color:var(--mute);font-weight:500;text-transform:none;padding:22px">Tidak ada aset pada pilihan ini.</td></tr>
                    @endforelse
                    </tbody>
                    @if($dp['rows']->isNotEmpty())
                    <tfoot><tr>
                        <td>Total</td>
                        <td class="c"><span class="pd-cnt pd-cnt-total">{{ number_format($dp['count']) }}</span></td>
                        <td class="r">{{ $rp($dp['value']) }}</td>
                    </tr></tfoot>
                    @endif
                </table>
            </div>
            <div class="pd-sub" style="margin-top:10px">Aset aktif (tanpa disposed dan dikembalikan), dikelompokkan menurut department pemegangnya.</div>
        </div>
    </section>

    {{-- CAPEX & OPEX --}}
    @php
        $sp = $this->spendData();
        $axis = fn ($v) => $v >= 1e9 ? rtrim(rtrim(number_format($v / 1e9, 1, ',', '.'), '0'), ',') . ' M' : ($v >= 1e6 ? rtrim(rtrim(number_format($v / 1e6, 1, ',', '.'), '0'), ',') . ' jt' : ($v >= 1e3 ? round($v / 1e3) . ' rb' : (string) round($v)));
        $mon = fn ($m) => \Illuminate\Support\Carbon::create(2000, $m, 1)->translatedFormat('M');
        $dl = fn ($p) => $p === null ? 'tidak ada pembanding' : ($p > 0 ? '▲ ' : ($p < 0 ? '▼ ' : '')) . abs($p) . '% vs ' . $sp['prevYear'];
        $plotH = 210;
    @endphp
    <section class="pd-grid">
        <div class="pd-card" id="capex-opex">
            <div class="pd-h" style="flex-wrap:wrap;gap:12px">
                <h3>CAPEX &amp; OPEX</h3>
                <div class="pd-ctl">
                    <select wire:model.live="spendYear" aria-label="Tahun">
                        @foreach($sp['years'] as $y)<option value="{{ $y }}">Tahun {{ $y }}</option>@endforeach
                    </select>
                </div>
            </div>

            <div class="pd-sp-tiles">
                <div class="pd-sp-tile"><div class="k"><i style="background:var(--sp1)"></i>CAPEX · belanja modal</div><div class="v">{{ $rp($sp['capex']) }}</div><div class="d">{{ $dl($sp['deltaCapex']) }}</div></div>
                <div class="pd-sp-tile"><div class="k"><i style="background:var(--sp2)"></i>OPEX · biaya operasional</div><div class="v">{{ $rp($sp['opex']) }}</div><div class="d">{{ $dl($sp['deltaOpex']) }}</div></div>
                <div class="pd-sp-tile"><div class="k">Total belanja {{ $sp['year'] }}</div><div class="v">{{ $rp($sp['total']) }}</div><div class="d">{{ $dl($sp['deltaTotal']) }}</div></div>
            </div>

            @if($sp['total'] > 0)
            <div class="pd-sp-legend"><span><i style="background:var(--sp1)"></i>CAPEX</span><span><i style="background:var(--sp2)"></i>OPEX</span><span>per bulan, {{ $sp['year'] }}</span></div>
            <div class="pd-sp-chart">
                <div class="pd-sp-y">
                    @foreach($sp['ticks'] as $t)<span style="bottom:{{ $t / $sp['axisMax'] * 100 }}%">{{ $axis($t) }}</span>@endforeach
                </div>
                <div class="pd-sp-plot">
                    @foreach($sp['ticks'] as $t)<div class="pd-sp-grid" style="bottom:{{ $t / $sp['axisMax'] * 100 }}%"></div>@endforeach
                    <div class="pd-sp-cols">
                        @foreach($sp['months'] as $m => $v)
                            @php
                                $hc = $v['capex'] > 0 ? max(2, round($v['capex'] / $sp['axisMax'] * $plotH)) : 0;
                                $ho = $v['opex'] > 0 ? max(2, round($v['opex'] / $sp['axisMax'] * $plotH)) : 0;
                                $edge = $m <= 3 ? 'l' : ($m >= 10 ? 'r' : '');
                            @endphp
                            <div class="pd-sp-g {{ $edge }}" tabindex="0" role="img" aria-label="{{ $mon($m) }} {{ $sp['year'] }}: CAPEX {{ $rp($v['capex']) }}, OPEX {{ $rp($v['opex']) }}">
                                <div class="pd-sp-b c" style="height:{{ $hc }}px"></div>
                                <div class="pd-sp-b o" style="height:{{ $ho }}px"></div>
                                @if($sp['peakCapex'] === $m)<span class="pd-sp-lbl c" style="bottom:{{ $hc + 4 }}px">{{ $rpShort($v['capex']) }}</span>@endif
                                @if($sp['peakOpex'] === $m)<span class="pd-sp-lbl o" style="bottom:{{ $ho + 4 }}px">{{ $rpShort($v['opex']) }}</span>@endif
                                <div class="pd-sp-tip">
                                    <b>{{ $mon($m) }} {{ $sp['year'] }}</b>
                                    <div><span><i style="background:var(--sp1)"></i>CAPEX</span><b style="margin:0">{{ $rp($v['capex']) }}</b></div>
                                    <div><span><i style="background:var(--sp2)"></i>OPEX</span><b style="margin:0">{{ $rp($v['opex']) }}</b></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="pd-sp-m">@foreach(range(1, 12) as $m)<span>{{ $mon($m) }}</span>@endforeach</div>
            </div>
            @else
                <div class="pd-empty" style="padding:18px 0">Belum ada catatan belanja pada tahun {{ $sp['year'] }}.</div>
            @endif

            <div class="pd-sp-brk">
                @foreach(['capex' => ['CAPEX', 'var(--sp1)'], 'opex' => ['OPEX', 'var(--sp2)']] as $kind => [$kname, $kcolor])
                    <div>
                        <h4><i style="background:{{ $kcolor }}"></i>Rincian {{ $kname }}</h4>
                        @foreach(collect($sp['lines'])->where('kind', $kind) as $line)
                            <div class="pd-sp-line">
                                <div class="r"><span>{{ $line['label'] }}</span><b>{{ $line['total'] > 0 ? $rp($line['total']) : '–' }}</b></div>
                                <div class="t"><span style="width:{{ $sp[$kind] > 0 ? round($line['total'] / $sp[$kind] * 100) : 0 }}%;background:{{ $kcolor }}"></span></div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            <details style="margin-top:16px">
                <summary style="cursor:pointer;font-size:.85rem;color:var(--mute);font-weight:600">Lihat tabel per bulan &amp; cara perhitungan</summary>
                <div style="overflow-x:auto">
                <table class="pd-sp-tbl">
                    <thead><tr><th>Bulan</th><th>CAPEX</th><th>OPEX</th><th>Total</th></tr></thead>
                    <tbody>
                    @foreach($sp['months'] as $m => $v)<tr><td>{{ $mon($m) }} {{ $sp['year'] }}</td><td>{{ $rp($v['capex']) }}</td><td>{{ $rp($v['opex']) }}</td><td>{{ $rp($v['capex'] + $v['opex']) }}</td></tr>@endforeach
                    <tr style="font-weight:700"><td>Total</td><td>{{ $rp($sp['capex']) }}</td><td>{{ $rp($sp['opex']) }}</td><td>{{ $rp($sp['total']) }}</td></tr>
                    </tbody>
                </table>
                </div>
                <p class="pd-sub" style="margin-top:10px;line-height:1.5">
                    <b>CAPEX</b>: pembelian aset milik sendiri (tanggal beli, termasuk yang sudah disposed), pembelian komponen/upgrade, dan lisensi perpetual.
                    <b>OPEX</b>: sewa aset (biaya bulanan dibagi per hari sampai hari ini, berhenti saat dikembalikan ke vendor), biaya perbaikan yang sudah selesai, pembelian aksesori dan barang habis pakai, serta langganan (subscription, domain, hosting, VPS) menurut tanggal beli.
                </p>
            </details>
        </div>
    </section>

    {{-- SEBARAN LOKASI & AKTIVITAS --}}
    <section class="pd-grid pd-cols">
        <div class="pd-card pd-c5">
            <div class="pd-h"><h3>Sebaran lokasi</h3><span>{{ $d['byLocation']->count() }} lokasi</span></div>
            @forelse($d['byLocation'] as $name => $n)
                <div class="pd-bar">
                    <div class="r"><span>{{ $name }}</span><b>{{ $n }}</b></div>
                    <div class="t"><span style="width:{{ $n / $locMax * 100 }}%"></span></div>
                </div>
            @empty <div class="pd-empty">Belum ada data</div> @endforelse
        </div>

        <div class="pd-card pd-c7">
            <div class="pd-h"><h3>Aktivitas terbaru</h3><span>serah terima & stok keluar</span></div>
            <div class="pd-list">
                @forelse($d['activity'] as $a)
                    @php [$ik, $ic] = $actIcon[$a['icon']] ?? ['box', '#64748b']; @endphp
                    <{{ $a['url'] ? 'a href=' . $a['url'] : 'div' }} class="pd-item">
                        <span class="ic" style="background:{{ $ic }}1f;color:{{ $ic }}">{!! $svg($ik) !!}</span>
                        <span class="tx"><b>{{ $a['title'] }}</b><span>{{ $a['sub'] }}</span></span>
                        <time>{{ $a['at']->diffForHumans(short: true) }}</time>
                    </{{ $a['url'] ? 'a' : 'div' }}>
                @empty <div class="pd-empty">Belum ada aktivitas</div> @endforelse
            </div>
        </div>
    </section>

    {{-- KEDATANGAN KARYAWAN --}}
    @if($d['arrivals']->count())
    <section class="pd-grid">
        <div class="pd-card">
            <div class="pd-h"><h3>Kedatangan karyawan onsite</h3><span>{{ $d['arrivals']->count() }} rencana ≤ 14 hari</span></div>
            <div class="pd-list">
                @foreach($d['arrivals'] as $pl)
                    @php $pr = $pl->progress(); $dl = $pl->days_left; @endphp
                    <a class="pd-item" href="{{ \App\Filament\Resources\ArrivalPlans\ArrivalPlanResource::getUrl('view', ['record' => $pl]) }}">
                        <span class="ic" style="background:{{ $pl->status === 'ready' ? '#2fa99b1f' : '#29aae11f' }};color:{{ $pl->status === 'ready' ? '#2fa99b' : '#29aae1' }}">{!! $svg('user') !!}</span>
                        <span class="tx"><b>{{ $pl->name }} · {{ $pl->location?->name ?? '-' }}</b><span>{{ $pr['ready'] }} dari {{ $pr['total'] }} barang siap · {{ \App\Models\ArrivalPlan::STATUSES[$pl->status] }}</span></span>
                        <time>{{ $dl < 0 ? 'lewat ' . abs($dl) . ' hr' : ($dl === 0 ? 'hari ini' : $dl . ' hari lagi') }}</time>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- ALERTS --}}
    <section class="pd-grid">
        <div class="pd-card">
            <div class="pd-h"><h3>Perlu perhatian</h3><span>{{ $alertCount }} peringatan</span></div>
            @if(! $alertCount)
                <div class="pd-ok">{!! $svg('check') !!} Tidak ada peringatan. Stok, lisensi, garansi, dan perbaikan dalam kondisi baik.</div>
            @else
                <div class="pd-tabs" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
                    @if($d['lowStock']->count())
                        <div class="pd-al"><h4>Stok menipis <em>{{ $d['lowStock']->count() }}</em></h4>
                            @foreach($d['lowStock']->take(5) as $s)<div class="row"><span>{{ $s['name'] }}</span><span style="color:#dc2626">{{ rtrim(rtrim(number_format($s['left'], 2, ',', '.'), '0'), ',') }} / min {{ rtrim(rtrim(number_format($s['min'], 2, ',', '.'), '0'), ',') }}</span></div>@endforeach</div>
                    @endif
                    @if($d['licenses']->count())
                        <div class="pd-al"><h4>Lisensi segera habis <em>{{ $d['licenses']->count() }}</em></h4>
                            @foreach($d['licenses']->take(5) as $l)<div class="row"><span>{{ $l->name }}</span><span style="color:#dc2626">{{ $l->expires_at->format('d/m/Y') }} · {{ (int) now()->startOfDay()->diffInDays($l->expires_at, false) }} hr</span></div>@endforeach</div>
                    @endif
                    @if($d['warranty']->count())
                        <div class="pd-al"><h4>Garansi habis ≤ 30 hari <em>{{ $d['warranty']->count() }}</em></h4>
                            @foreach($d['warranty']->take(5) as $a)<div class="row"><span>{{ $a->asset_tag }} {{ $a->model }}</span><span style="color:#1f78b0">{{ $a->warranty_until->format('d/m/Y') }}</span></div>@endforeach</div>
                    @endif
                    @if($d['lateRepairs']->count())
                        <div class="pd-al"><h4>Perbaikan &gt; 14 hari <em>{{ $d['lateRepairs']->count() }}</em></h4>
                            @foreach($d['lateRepairs']->take(5) as $r)<div class="row"><span>{{ $r->asset?->asset_tag }}</span><span style="color:#dc2626">{{ (int) $r->reported_at->diffInDays(now()) }} hari</span></div>@endforeach</div>
                    @endif
                    @if($d['resignedHolding']->count())
                        <div class="pd-al"><h4>Resign masih pegang barang <em>{{ $d['resignedHolding']->count() }}</em></h4>
                            @foreach($d['resignedHolding']->take(5) as $e)<div class="row"><span>{{ $e->name }}</span><span style="color:#dc2626">tarik barang</span></div>@endforeach</div>
                    @endif
                    @if($d['arrivalsAtRisk']->count())
                        <div class="pd-al"><h4>Kedatangan ≤ 7 hari belum siap <em>{{ $d['arrivalsAtRisk']->count() }}</em></h4>
                            @foreach($d['arrivalsAtRisk']->take(5) as $pl)@php $pr = $pl->progress(); @endphp<div class="row"><span>{{ $pl->name }}</span><span style="color:#dc2626">{{ $pr['ready'] }}/{{ $pr['total'] }} siap · {{ $pl->days_left < 0 ? 'lewat ' . abs($pl->days_left) . ' hr' : $pl->days_left . ' hr' }}</span></div>@endforeach</div>
                    @endif
                    @if($d['rentalExpiring']->count())
                        <div class="pd-al"><h4>Sewa aset segera berakhir <em>{{ $d['rentalExpiring']->count() }}</em></h4>
                            @foreach($d['rentalExpiring']->take(5) as $a)<div class="row"><span>{{ $a->asset_tag }} {{ $a->model }}</span><span style="color:#dc2626">{{ $a->rental_days_left < 0 ? 'lewat ' . abs($a->rental_days_left) . ' hr' : $a->rental_end->format('d/m/Y') . ' · ' . $a->rental_days_left . ' hr' }}</span></div>@endforeach</div>
                    @endif
                    @if($d['overSeats']->count())
                        <div class="pd-al"><h4>Seat melebihi lisensi <em>{{ $d['overSeats']->count() }}</em></h4>
                            @foreach($d['overSeats']->take(5) as $l)<div class="row"><span>{{ $l->name }}</span><span style="color:#dc2626">{{ $l->seats_used }} / {{ $l->seats }}</span></div>@endforeach</div>
                    @endif
                </div>
            @endif
        </div>
    </section>
</div>
</x-filament-panels::page>
