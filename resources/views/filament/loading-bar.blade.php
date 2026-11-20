{{-- Bilah loading di atas layar: tampil saat berpindah halaman, mengirim formulir, dan setiap permintaan Livewire (tabel, filter, aksi) yang lebih dari ±150 ms. --}}
<div id="akp-loading" aria-hidden="true"><span></span></div>
<style>
    #akp-loading { position: fixed; top: 0; left: 0; right: 0; height: 3px; z-index: 99999; pointer-events: none; opacity: 0; transition: opacity .2s; }
    #akp-loading.on { opacity: 1; }
    #akp-loading span { display: block; height: 100%; width: 40%; background: linear-gradient(90deg, #29aae1, #37baab); border-radius: 0 3px 3px 0; animation: akp-load 1.1s ease-in-out infinite; }
    @keyframes akp-load { 0% { transform: translateX(-100%); } 100% { transform: translateX(260%); } }
</style>
<script>
    (function () {
        var bar = document.getElementById('akp-loading'), pending = 0, timer = null;
        function start() { pending++; if (!timer) timer = setTimeout(function () { bar.classList.add('on'); }, 150); }
        function stop() { pending = Math.max(0, pending - 1); if (pending === 0) { clearTimeout(timer); timer = null; bar.classList.remove('on'); } }
        function reset() { pending = 0; clearTimeout(timer); timer = null; bar.classList.remove('on'); }

        // berpindah halaman (tautan internal) dan kirim formulir biasa
        document.addEventListener('click', function (e) {
            var a = e.target.closest && e.target.closest('a[href]');
            if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
            if (a.target === '_blank' || a.hasAttribute('download') || a.getAttribute('href').charAt(0) === '#') return;
            if (a.hasAttribute('wire:click') || a.hasAttribute('x-on:click') || a.hasAttribute('@click')) return;
            try { var u = new URL(a.href, location.href); if (u.origin !== location.origin || (u.pathname === location.pathname && u.search === location.search)) return; } catch (x) { return; }
            start();
        }, true);
        window.addEventListener('beforeunload', function () { if (!pending) start(); });
        window.addEventListener('pageshow', reset);

        // permintaan Livewire (filter, cari, urut, aksi, pagination)
        document.addEventListener('livewire:init', function () {
            Livewire.hook('commit', function (ctx) {
                start();
                ctx.respond(function () { stop(); });
                ctx.fail(function () { stop(); });
            });
        });
    })();
</script>
