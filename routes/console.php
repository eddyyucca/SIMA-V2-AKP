<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('inventory:notify daily')->dailyAt('08:00');
Schedule::command('inventory:notify weekly')->weeklyOn(1, '08:00');
Schedule::command('inventory:notify monthly')->monthlyOn(1, '08:00');

// System Requisition: reminder Peminjaman Aset yang jatuh tempo/terlambat, tiap 30 menit
// (peminjaman jam-an juga cepat terdeteksi). Butuh scheduler berjalan (container scheduler).
Schedule::command('peminjaman:remind')->everyThirtyMinutes();
