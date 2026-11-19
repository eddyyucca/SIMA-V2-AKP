<?php

/*
 * Login terpusat lewat Portal AKPSatu (paket akp/portal-sso). Nilai rahasia dari .env.
 * SSO_ENABLED=false (bawaan) = login NIK + password lokal tetap dipakai; ubah ke true saat modul "asset" sudah terdaftar di Portal.
 */
return [
    'portal_url' => env('PORTAL_URL', 'http://localhost:8000'),
    'app_code' => env('PORTAL_APP_CODE', 'asset'),
    'secret' => env('PORTAL_SSO_SECRET'),
    'leeway' => 5,
    'max_session_minutes' => (int) env('PORTAL_MAX_SESSION_MINUTES', 480),

    // Filament memakai grup middleware sendiri, jadi perlindungan dipasang eksplisit (EnforcePortalSession), bukan lewat grup 'web'.
    'protect_all' => false,
    'except' => ['up'],
    'home' => '/admin',

    // Level yang disediakan modul ini; dibaca Portal (Modul > Atur > Deteksi level). Kode jangan diubah setelah dipakai.
    'roles' => [
        'admin' => ['Admin', 'Semua: master data, pengaturan, hapus data, persetujuan disposal, approval request.'],
        'staff' => ['Staff IT', 'Mengelola inventaris dan transaksi, memproses request. Tanpa pengaturan.'],
        'user' => ['User', 'Karyawan: mengirim request ITERA dan melihat barang miliknya.'],
    ],

    // Aplikasi ini
    'enabled' => (bool) env('SSO_ENABLED', false),
    // Login lokal (NIK + password) tetap bisa dibuka lewat /admin/login?lokal=1 sebagai akun darurat. Matikan setelah serah-terima selesai.
    'allow_local_login' => (bool) env('SSO_ALLOW_LOCAL_LOGIN', true),
    // kode level Portal -> peran lokal (Spatie). Super admin Portal selalu Admin; level tak dikenal = User.
    'role_map' => ['admin' => 'Admin', 'staff' => 'Staff IT', 'user' => 'User'],
];
