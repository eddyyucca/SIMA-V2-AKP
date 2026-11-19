{{--
    Overlay loading + sukses premium untuk form yang dikirim via AJAX (dipasang DI DALAM kartu modal berposisi relative).
    Parameter:
      $prefix  : awalan id (modal / edit / note) - id yang dihasilkan: {prefix}StatusOverlay, {prefix}StatusSpinner,
                 {prefix}StatusSuccess, {prefix}StatusSuccessText, {prefix}StatusNote, {prefix}StatusOkButton
      $loading : teks saat memproses
      $withOk  : true = tampilkan tombol "Selesai" (halaman dimuat ulang saat diklik)
--}}
<div id="{{ $prefix }}StatusOverlay" class="hidden sr-overlay absolute inset-0 z-20 flex flex-col items-center justify-center rounded-2xl" role="status" aria-live="polite">
    <div id="{{ $prefix }}StatusSpinner" class="sr-state sr-loading">
        <div class="sr-orb" aria-hidden="true">
            <span class="sr-ring"></span>
            <span class="sr-ring sr-ring-2"></span>
            <span class="sr-core"></span>
        </div>
        <p class="sr-title">{{ $loading ?? 'Memproses...' }}</p>
        <p class="sr-sub">Mohon tunggu sebentar, jangan tutup halaman ini.</p>
        <div class="sr-bar" aria-hidden="true"><i></i></div>
    </div>

    <div id="{{ $prefix }}StatusSuccess" class="hidden sr-state sr-done">
        <div class="sr-badge" aria-hidden="true">
            <span class="sr-halo"></span>
            <span class="sr-halo sr-halo-2"></span>
            <svg class="sr-check" viewBox="0 0 52 52">
                <circle class="sr-check-circle" cx="26" cy="26" r="24"></circle>
                <path class="sr-check-mark" d="M15 27l8 8 15-17"></path>
            </svg>
        </div>
        <p class="sr-kicker">Berhasil</p>
        <p id="{{ $prefix }}StatusSuccessText" class="sr-title"></p>
        <p id="{{ $prefix }}StatusNote" class="sr-sub"></p>
        @if (! empty($withOk))
            <button type="button" id="{{ $prefix }}StatusOkButton" class="sr-ok">Selesai</button>
        @endif
    </div>
</div>
