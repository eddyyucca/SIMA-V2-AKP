{{-- Akun di sidebar: klik nama untuk membuka menu (Log Out di dalamnya). Tanpa Alpine: dipakai di halaman mandiri. --}}
@php $amDir = $dir ?? 'up'; $amItems = $items ?? []; @endphp
<div class="am-wrap {{ $amDir === 'down' ? 'am-down' : '' }}" id="amWrap">
    <button type="button" class="am-btn" id="amBtn" aria-haspopup="menu" aria-expanded="false" aria-controls="amMenu" title="Menu akun">
        <span class="am-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
        <span class="am-name">{{ auth()->user()->name }}</span>
        <svg class="am-chev" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M14.707 12.707a1 1 0 0 1-1.414 0L10 9.414l-3.293 3.293a1 1 0 0 1-1.414-1.414l4-4a1 1 0 0 1 1.414 0l4 4a1 1 0 0 1 0 1.414Z" clip-rule="evenodd"/></svg>
    </button>
    <div class="am-menu" id="amMenu" role="menu" hidden>
        <div class="am-who"><b>{{ auth()->user()->name }}</b><span>{{ auth()->user()->email }}</span></div>
        @foreach($amItems as $it)
            <a href="{{ $it['href'] }}" class="am-item am-link" role="menuitem">{{ $it['label'] }}</a>
        @endforeach
        @if($amItems)<div class="am-sep"></div>@endif
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="am-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/></svg>
                Log Out
            </button>
        </form>
    </div>
</div>
<style>
    .am-wrap{position:relative;min-width:0}
    .am-btn{display:flex;align-items:center;gap:10px;width:100%;padding:6px 8px;border:1px solid transparent;border-radius:12px;background:transparent;cursor:pointer;text-align:left;color:inherit;transition:background .15s,border-color .15s}
    .am-btn:hover,.am-btn[aria-expanded="true"]{background:#eef3fa;border-color:#d6e2f1}
    .am-btn:focus-visible{outline:3px solid #29aae1;outline-offset:1px}
    .am-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#1f6fb5,#29aae1);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex:none}
    .am-name{flex:1;min-width:0;font-size:14px;font-weight:650;color:#10264a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .am-chev{width:16px;height:16px;color:#64748b;flex:none;transition:transform .15s}
    .am-btn[aria-expanded="true"] .am-chev{transform:rotate(180deg)}
    .am-menu{position:absolute;left:0;right:0;bottom:calc(100% + 8px);z-index:50;background:#fff;border:1px solid #d6e2f1;border-radius:12px;box-shadow:0 14px 30px -12px rgba(15,38,74,.35);padding:6px;min-width:200px}
    .am-menu[hidden]{display:none}
    .am-who{padding:8px 10px 10px;border-bottom:1px solid #e8eef6;margin-bottom:4px}
    .am-who b{display:block;font-size:13px;color:#10264a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .am-who span{display:block;font-size:11.5px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .am-item{display:flex;align-items:center;gap:9px;width:100%;padding:9px 10px;border:0;border-radius:8px;background:transparent;color:#b13a3a;font-size:13px;font-weight:650;cursor:pointer;text-align:left}
    .am-item svg{width:17px;height:17px;flex:none}
    .am-link{color:#195f9d;text-decoration:none}.am-link:hover,.am-link:focus-visible{background:#e8f1fa!important}
    .am-sep{height:1px;background:#e8eef6;margin:4px 0}
    .am-down .am-btn{width:auto}
    .am-down .am-menu{bottom:auto;top:calc(100% + 8px);left:auto;right:0;width:240px}
    .am-item:hover,.am-item:focus-visible{background:#fdecec;outline:none}
</style>
<script>
(function () {
    var btn = document.getElementById('amBtn'), menu = document.getElementById('amMenu');
    if (!btn || !menu) return;
    function set(v) { menu.hidden = !v; btn.setAttribute('aria-expanded', v ? 'true' : 'false'); if (v) { try { menu.scrollIntoView({ block: 'nearest' }); } catch (e) {} } }
    btn.addEventListener('click', function (e) { e.stopPropagation(); set(menu.hidden); });
    document.addEventListener('click', function (e) { if (!menu.hidden && !menu.contains(e.target)) set(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !menu.hidden) { set(false); btn.focus(); } });
})();
</script>
