<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('inventory:notify daily')->dailyAt('08:00');
Schedule::command('inventory:notify weekly')->weeklyOn(1, '08:00');
Schedule::command('inventory:notify monthly')->monthlyOn(1, '08:00');

// System Requisition: reminder Peminjaman Aset yang jatuh tempo/terlambat, tiap 30 menit
// (peminjaman jam-an juga cepat terdeteksi). Butuh scheduler berjalan (container scheduler).
Schedule::command('peminjaman:remind')->everyThirtyMinutes();

// HRIS = sumber kebenaran karyawan (aktif/resign, jabatan, department). Hanya berjalan bila HRIS_API_URL & HRIS_API_TOKEN terisi.
if (filled(config('services.hris.url')) && filled(config('services.hris.token'))) {
    Schedule::command('hris:sync-employees --apply')->dailyAt('02:00')->withoutOverlapping();
}
