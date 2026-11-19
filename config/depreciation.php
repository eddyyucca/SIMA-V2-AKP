<?php

/**
 * Penyusutan aset: garis lurus per bulan, mulai dari bulan tanggal beli (pro-rata bulanan).
 * Aset sewa tidak disusutkan. Barang di bawah ambang langsung dibebankan (nilai buku 0).
 */
return [
    'threshold' => (float) env('DEPRECIATION_THRESHOLD', 1000000),
    'default_life_years' => (int) env('DEPRECIATION_DEFAULT_LIFE', 4),
    'default_residual_percent' => (float) env('DEPRECIATION_DEFAULT_RESIDUAL', 0),
];
