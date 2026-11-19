<?php

namespace App\Console\Commands;

use App\Models\ITRequest;
use App\Models\User;
use App\Notifications\PeminjamanReturnReminder;
use Illuminate\Console\Command;

// Dijalankan terjadwal tiap 30 menit (lihat routes/console.php). Mengingatkan tim IT untuk Peminjaman
// Aset dalam 3 tahap, masing-masing dicatat lewat kolom *_reminder_sent_at supaya tidak dikirim ulang
// tiap kali command jalan:
// 1. H-30 menit sebelum Tanggal Kembali - sekali.
// 2. Pas Tanggal Kembali tercapai - sekali.
// 3. Berulang tiap 6 jam sejak itu, selama belum ditandai "Sudah Kembali".
class SendPeminjamanReminders extends Command
{
    protected $signature = 'peminjaman:remind';

    protected $description = 'Kirim reminder ke tim IT untuk Peminjaman Aset yang akan/sudah jatuh tempo';

    public function handle(): int
    {
        $itTeam = User::requisitionAdmins()->get();
        $sentCount = 0;

        $baseQuery = fn () => ITRequest::where('type', 'PEMINJAMAN_ASET')
            ->whereIn('status', ['Approved', 'In Progress'])
            ->whereNull('returned_at');

        // 1. H-30 menit sebelum jatuh tempo (sekali per peminjaman)
        $upcoming = $baseQuery()
            ->whereNull('pre_due_reminder_sent_at')
            ->where('loan_end_date', '>', now())
            ->where('loan_end_date', '<=', now()->addMinutes(30))
            ->get();

        foreach ($upcoming as $item) {
            $itTeam->each(fn ($admin) => $admin->notify(new PeminjamanReturnReminder($item, 'upcoming')));
            $item->update(['pre_due_reminder_sent_at' => now()]);
            $sentCount++;
        }

        // 2. Tepat saat jatuh tempo (sekali) - sekaligus jadi acuan waktu untuk siklus 6 jam berikutnya
        $justDue = $baseQuery()
            ->whereNull('due_reminder_sent_at')
            ->where('loan_end_date', '<=', now())
            ->get();

        foreach ($justDue as $item) {
            $itTeam->each(fn ($admin) => $admin->notify(new PeminjamanReturnReminder($item, 'due')));
            $item->update(['due_reminder_sent_at' => now(), 'last_overdue_reminder_at' => now()]);
            $sentCount++;
        }

        // 3. Berulang tiap 6 jam setelah jatuh tempo, sampai ditandai "Sudah Kembali"
        $stillOverdue = $baseQuery()
            ->whereNotNull('due_reminder_sent_at')
            ->where('loan_end_date', '<=', now())
            ->where('last_overdue_reminder_at', '<=', now()->subHours(6))
            ->get();

        foreach ($stillOverdue as $item) {
            $itTeam->each(fn ($admin) => $admin->notify(new PeminjamanReturnReminder($item, 'overdue')));
            $item->update(['last_overdue_reminder_at' => now()]);
            $sentCount++;
        }

        if ($sentCount === 0) {
            $this->info('Tidak ada reminder peminjaman yang perlu dikirim.');

            return self::SUCCESS;
        }

        $this->info($sentCount . ' reminder peminjaman terkirim ke ' . $itTeam->count() . ' admin.');

        return self::SUCCESS;
    }
}
