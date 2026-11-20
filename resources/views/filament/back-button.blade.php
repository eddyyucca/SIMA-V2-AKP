{{-- Tombol "Kembali" di awal setiap halaman panel (kecuali Dashboard): kembali ke halaman sebelumnya; bila dibuka langsung (tanpa riwayat), ke Dashboard. --}}
@unless (request()->routeIs('filament.admin.pages.dashboard'))
    <div class="akp-back-wrap">
        <button type="button" class="akp-back" onclick="akpBack('{{ \Filament\Facades\Filament::getUrl() }}')" title="Kembali ke halaman sebelumnya">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
            Kembali
        </button>
    </div>
    <style>
        .akp-back-wrap { margin: 0 0 .5rem; }
        .akp-back { display: inline-flex; align-items: center; gap: .4rem; padding: .35rem .8rem; border-radius: .5rem; font-size: .85rem; font-weight: 600;
            border: 1px solid rgba(128,128,128,.35); background: transparent; color: inherit; cursor: pointer; }
        .akp-back:hover { background: rgba(128,128,128,.12); }
    </style>
    <script>
        function akpBack(fallback) {
            try {
                if (document.referrer && new URL(document.referrer).origin === location.origin && history.length > 1) { history.back(); return; }
            } catch (e) {}
            location.href = fallback;
        }
    </script>
@endunless
