{{-- Gaya bersama halaman SIMA Agent (mengikuti tema terang/gelap Filament lewat warna transparan & currentColor). --}}
<style>
    .am-kpis { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
    .am-kpi { flex: 1 1 150px; border: 1px solid rgba(128,128,128,.25); border-radius: 8px; padding: 12px 14px; border-left-width: 4px; background: rgba(128,128,128,.04); }
    .am-kpi .k-label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; opacity: .7; }
    .am-kpi .k-value { font-size: 26px; font-weight: 600; line-height: 1.2; }
    .am-kpi .k-sub { font-size: 12px; opacity: .7; }
    .am-kpi.ok { border-left-color: #00a65a; } .am-kpi.bad { border-left-color: #dd4b39; } .am-kpi.warn { border-left-color: #f39c12; }
    .am-kpi.neutral { border-left-color: #8a96a8; } .am-kpi.info { border-left-color: #3c8dbc; }
    .am-card { border: 1px solid rgba(128,128,128,.25); border-radius: 10px; padding: 16px 18px; margin-bottom: 16px; background: rgba(128,128,128,.03); }
    .am-card h4 { margin: 0 0 12px; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; opacity: .75; }
    .am-grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .am-grid2 > .am-card, .am-grid3 > .am-card { margin-bottom: 0; }
    .am-grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
    .am-stack > * + * { margin-top: 16px; }
    @media (max-width: 900px) { .am-grid2, .am-grid3 { grid-template-columns: 1fr; } }
    .am-muted { opacity: .65; font-size: 12px; }
    .am-big { font-size: 46px; font-weight: 700; line-height: 1; }
    .am-seg { display: flex; height: 22px; border-radius: 11px; overflow: hidden; background: rgba(128,128,128,.2); margin: 12px 0 8px; }
    .am-seg > div { height: 100%; }
    .am-legend { display: flex; flex-wrap: wrap; gap: 14px; font-size: 12px; }
    .am-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 5px; }
    .am-row { display: flex; align-items: center; gap: 10px; margin: 7px 0; font-size: 13px; }
    .am-row .r-label { flex: 0 0 38%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .am-row .r-bar { flex: 1 1 auto; }
    .am-row .r-num { flex: 0 0 74px; text-align: right; font-weight: 600; }
    .am-bar { height: 8px; border-radius: 4px; background: rgba(128,128,128,.2); overflow: hidden; }
    .am-bar > span { display: block; height: 100%; border-radius: 4px; background: #00a65a; }
    .am-bar.warn > span { background: #f39c12; } .am-bar.bad > span { background: #dd4b39; } .am-bar.info > span { background: #3c8dbc; } .am-bar.gray > span { background: #8a96a8; }
    .am-pill { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; color: #fff; background: #8a96a8; white-space: nowrap; }
    .am-pill.ok { background: #00a65a; } .am-pill.bad { background: #dd4b39; } .am-pill.warn { background: #f39c12; } .am-pill.info { background: #3c8dbc; }
    .am-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .am-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; opacity: .7; padding: 6px 8px; border-bottom: 1px solid rgba(128,128,128,.3); white-space: nowrap; }
    .am-table td { padding: 7px 8px; border-bottom: 1px solid rgba(128,128,128,.15); vertical-align: top; }
    .am-table a { color: rgb(var(--primary-600, 31 120 176)); font-weight: 600; }
    .am-scroll { overflow: auto; max-height: 440px; }
    .am-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; }
    .am-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px; border: 1px solid rgba(128,128,128,.4); font-size: 13px; font-weight: 600; text-decoration: none; color: inherit; }
    .am-btn:hover { background: rgba(128,128,128,.12); }
    .am-tabs { display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 1px solid rgba(128,128,128,.25); margin-bottom: 14px; }
    .am-tabs button, .am-tabs a { padding: 8px 14px; font-weight: 600; opacity: .7; border: 0; background: none; color: inherit; border-bottom: 3px solid transparent; cursor: pointer; text-decoration: none; font-size: 14px; }
    .am-tabs .on { opacity: 1; border-bottom-color: rgb(var(--primary-600, 31 120 176)); }
    .am-kv { display: grid; grid-template-columns: 38% 1fr; gap: 6px 12px; font-size: 13px; }
    .am-kv dt { opacity: .65; } .am-kv dd { margin: 0; font-weight: 500; word-break: break-word; }
    .am-callout { border: 1px solid rgba(60,141,188,.4); background: rgba(60,141,188,.08); border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; font-size: 13px; }
</style>
