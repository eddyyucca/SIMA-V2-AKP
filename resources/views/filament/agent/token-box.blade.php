{{-- Token agent yang baru dibuat: tampil di halaman (bukan notifikasi) sampai agent terpasang (lapor pertama) atau token diperbarui / dicabut. --}}
@php $plainToken = \App\Models\AssetAgentToken::pendingPlain($asset); @endphp
@if ($plainToken)
    <div x-data="{ copied: false }" style="border:2px solid #d9a02b;background:rgba(217,160,43,.12);border-radius:12px;padding:14px 16px;margin-bottom:14px">
        <div style="font-weight:700;margin-bottom:4px">Token agent untuk {{ $asset->asset_tag }}</div>
        <div style="font-size:13px;opacity:.8;margin-bottom:8px">Isikan pada kolom Token Agent di installer (SIMA-Asset-Agent-Setup.exe). Token ini otomatis hilang dari halaman begitu agent terpasang dan melapor, atau saat token diperbarui atau dicabut.</div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input type="text" readonly value="{{ $plainToken }}" x-ref="tok" @focus="$el.select()" style="flex:1;min-width:280px;font-family:ui-monospace,monospace;font-size:15px;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;background:transparent;color:inherit">
            <button type="button" @click="navigator.clipboard ? navigator.clipboard.writeText($refs.tok.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) }) : ($refs.tok.select(), document.execCommand('copy'), copied = true, setTimeout(() => copied = false, 2000))"
                style="padding:8px 16px;border-radius:8px;background:#1f6fb5;color:#fff;font-weight:600"><span x-text="copied ? 'Tersalin ✓' : 'Salin'"></span></button>
        </div>
    </div>
@endif
