<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('inventory:notify daily')->dailyAt('08:00');
Schedule::command('inventory:notify weekly')->weeklyOn(1, '08:00');
Schedule::command('inventory:notify monthly')->monthlyOn(1, '08:00');

// System Requisition: reminder Peminjaman Aset yang jatuh tempo/terlambat, tiap 30 menit
// (peminjaman jam-an juga cepat terdeteksi). Butuh scheduler berjalan (container scheduler).
Schedule::command('peminjaman:remind')->everyThirtyMinutes();
Schedule::command('requisition:escalate-supervisor')->hourly();   // request yang menunggu atasan > 2 hari diteruskan ke Tim IT

// HRIS = sumber kebenaran karyawan (aktif/resign, jabatan, department). Hanya berjalan bila HRIS_API_URL & HRIS_API_TOKEN terisi.
if (filled(config('services.hris.url')) && filled(config('services.hris.token'))) {
    Schedule::command('hris:sync-employees --apply')->dailyAt('02:00')->withoutOverlapping();
}

Schedule::command("agent:mark-stale-offline")->everyFiveMinutes();   // agent yang berhenti lapor -> offline
Schedule::command("agent:rollout-snapshot")->dailyAt("23:55");           // foto harian pencapaian rollout (grafik tren)
