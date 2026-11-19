{{-- Pembantu kirim form via AJAX: timeout, pesan galat yang jelas, kunci anti-kirim-ganda, dan scroll-lock overlay. --}}
<script>
    // Pesan ramah untuk galat umum (kembalikan null kalau galat validasi 422 - ditampilkan sebagai daftar).
    window.srFriendlyError = function (status, data) {
        if (data && data.errors) return null;
        if (status === 419) return 'Sesi Anda sudah berakhir. Muat ulang halaman, lalu coba lagi.';
        if (status === 401) return 'Anda sudah keluar dari sistem. Silakan login kembali.';
        if (status === 403) return (data && data.message) || 'Anda tidak memiliki akses untuk aksi ini.';
        if (status === 413) return 'Ukuran file terlalu besar (maksimal 5 MB).';
        if (status === 429) return 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.';
        if (status >= 500) return 'Terjadi gangguan di server. Data Anda belum tersimpan, silakan coba lagi sebentar lagi.';
        return (data && data.message) || null;
    };

    // POST FormData; resolve {ok,status,data}. Reject dengan {timeout:true} jika lewat batas waktu.
    window.srPost = function (url, formData, timeoutMs) {
        const controller = new AbortController();
        const timer = setTimeout(function () { controller.abort(); }, timeoutMs || 45000);
        return fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: formData,
            signal: controller.signal,
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                return { ok: res.ok, status: res.status, data: data };
            });
        }).catch(function (err) {
            if (err && err.name === 'AbortError') { throw { timeout: true }; }
            throw err;
        }).finally(function () { clearTimeout(timer); });
    };

    // Tampilkan overlay di dalam kartu modal: gulung ke atas & kunci scroll supaya indikator pasti terlihat
    // (kartu modal bisa lebih tinggi dari layar, terutama di HP).
    window.srShowOverlay = function (prefix) {
        const overlay = document.getElementById(prefix + 'StatusOverlay');
        const card = overlay.parentElement;
        card.scrollTop = 0;
        card.style.overflow = 'hidden';
        document.getElementById(prefix + 'StatusSpinner').classList.remove('hidden');
        document.getElementById(prefix + 'StatusSuccess').classList.add('hidden');
        overlay.classList.remove('hidden');
    };

    window.srHideOverlay = function (prefix) {
        const overlay = document.getElementById(prefix + 'StatusOverlay');
        overlay.classList.add('hidden');
        overlay.parentElement.style.overflow = '';
    };
</script>
