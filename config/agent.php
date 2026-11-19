<?php

/**
 * SIMA Asset Agent (custom SIMA): pengaturan kepatuhan dan rollout. Semua bisa diatur lewat environment (.env / Portainer).
 */
return [

    // Folder paket update agent (manifest.json + agent-N.zip). Lihat App\Services\AgentUpdateManifest.
    'updates_path' => base_path('agent-updates'),

    // Akun yang BOLEH menjadi administrator lokal di laptop (tanpa memperhatikan domain/komputer, huruf besar-kecil
    // diabaikan), dipisah koma. Anggota grup Administrators di luar daftar ini diperingatkan sebagai "pengguna harian
    // punya hak admin". Sesuaikan dengan akun admin IT yang Anda buat, mis. "Administrator,ITAdmin,Domain Admins".
    'admin_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('AGENT_ADMIN_ALLOWLIST', 'Administrator'))))),

    // Bila true, laptop yang penyimpanan USB-nya masih terbuka diberi peringatan. Biarkan false sampai kebijakan blokir USB
    // benar-benar diputuskan, supaya laporan tidak penuh peringatan untuk sesuatu yang belum diwajibkan.
    'require_usb_block' => filter_var(env('AGENT_REQUIRE_USB_BLOCK', false), FILTER_VALIDATE_BOOLEAN),

    // Nama kategori aset (tabel categories) yang dihitung sebagai "laptop" untuk dashboard pencapaian rollout agent.
    'laptop_categories' => array_values(array_filter(array_map('trim', explode(',', (string) env('AGENT_LAPTOP_CATEGORIES', 'LAPTOP'))))),

    // Perangkat dianggap "online sekarang" bila melapor dalam sekian menit terakhir (selaras MarkStaleAssetsOffline).
    'online_minutes' => 15,
];
