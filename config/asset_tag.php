<?php

/** Kode aset: {AWALAN}-{KODE KATEGORI}-{NOMOR URUT 4 DIGIT}, mis. AKP-IT-NB-0001. Awalan dapat diubah di menu Pengaturan. */
return [
    'prefix' => env('ASSET_TAG_PREFIX', 'AKP-IT'),
    'digits' => (int) env('ASSET_TAG_DIGITS', 4),
];
