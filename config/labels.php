<?php

/**
 * Label pita Brother PT-D610BT (pita TZe: 3.5 / 6 / 9 / 12 / 18 / 24 mm).
 * Ukuran halaman PDF = lebar pita x panjang label, satu label per halaman, tanpa margin. Panjang aset 70 mm = Length di Printing Preferences driver Brother PT-D610BT
 * (Chrome memakai ukuran kertas driver, bukan ukuran PDF), jadi label mengisi kertas tanpa sisa. Ubah lewat LABEL_LENGTH_MM bila panjang kertas driver diubah.
 *  - Aset: pita 24 mm, tata letak berbingkai (perusahaan, kode aset, serial number, QR) — sesuai contoh.
 *  - Accessories / Komponen: pita 9 mm, tata letak ringkas (QR + "PT. AKP" + nomor kode, mis. ACC-0001). Consumable tidak dilabeli.
 */
return [
    'tape' => (int) env('LABEL_TAPE_MM', 24),
    'length' => (int) env('LABEL_LENGTH_MM', 70),
    'other' => [
        'tape' => (int) env('LABEL_OTHER_TAPE_MM', 9),
        'length' => (int) env('LABEL_OTHER_LENGTH_MM', 30),
        // tulisan di atas nomor kode pada label Accessories / Komponen
        'company' => env('LABEL_OTHER_COMPANY', 'PT. AKP'),
    ],
    'tapes' => [6, 9, 12, 18, 24],
    // tidak dipakai lagi: QR pada label selalu berisi kode barang (bukan tautan)
    'qr_mode' => env('LABEL_QR_MODE', 'code'),
    'company' => env('LABEL_COMPANY', 'PT. ADHI KARTIKO PRATAMA'),
];
