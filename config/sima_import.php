<?php

/*
 * Aturan impor SIMA (Snipe-IT) -> aplikasi ini. Semua pemetaan yang bergantung pada data produksi ada di sini,
 * supaya bisa disesuaikan setelah laporan profil data (php artisan sima:import --dry-run) tanpa mengubah kode.
 */
return [
    // Nama status_labels SIMA (huruf kecil, cocok persis) -> status aset baru. Yang tidak ada di sini dipetakan dari
    // bendera deployable/pending/archived + kata kunci (lihat SimaImporter::mapStatus) dan DILAPORKAN sebagai "status tak dikenal".
    'status_overrides' => [
        // 'rusak berat' => 'disposed',
    ],

    // Awalan kode kategori aset (categories.code_prefix) berdasarkan nama kategori (huruf kecil, mengandung kata).
    'category_prefix' => [
        'laptop' => 'NB', 'notebook' => 'NB', 'monitor' => 'MNT', 'pc' => 'PC', 'desktop' => 'PC', 'printer' => 'PRT',
        'network' => 'NET', 'jaringan' => 'NET', 'server' => 'SRV', 'handphone' => 'HP', 'phone' => 'HP', 'tablet' => 'TAB',
        'ups' => 'UPS', 'scanner' => 'SCN', 'cctv' => 'CCTV', 'projector' => 'PRJ',
    ],

    // Tag lama yang sudah berformat tag baru dipertahankan; selain itu dibuat tag baru dan tag lama disimpan sebagai alias.
    'keep_tag_pattern' => '/^AKP-IT-[A-Z0-9]+-\d+$/',

    // Folder berkas SIMA (public/uploads + storage/private_uploads) untuk disalin: foto aset & PDF BAST. Bisa juga lewat --files=.
    'files_path' => env('SIMA_FILES_PATH'),
];
