<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** migrate:fresh dijalankan oleh tes: tolak database selain *_test supaya data asli tidak pernah terhapus. */
    protected function setUp(): void
    {
        parent::setUp();
        $db = (string) config('database.connections.' . config('database.default') . '.database');
        if (! str_ends_with($db, '_test')) {
            throw new \RuntimeException("Tes menolak jalan di database '$db' (nama harus berakhiran _test).");
        }
        $this->guardRealStorage();
        // assertNotifiedFailure(): ada notifikasi merah (danger) yang dikirim, apa pun judulnya
        if (! \Livewire\Features\SupportTesting\Testable::hasMacro('assertNotifiedFailure')) {
            \Livewire\Features\SupportTesting\Testable::macro('assertNotifiedFailure', function () {
                app(\Filament\Notifications\NotificationMailbox::class)->flush();
                $n = new \Filament\Notifications\Livewire\Notifications;
                $n->mount(); $n->pullNotifications();
                \PHPUnit\Framework\Assert::assertTrue($n->notifications->contains(fn ($x) => $x->getStatus() === 'danger'), 'Notifikasi gagal (merah) tidak muncul.');
                return $this;
            });
        }
    }

    /**
     * Berkas yang dibuat tes (PDF BAST, dokumen, foto) tidak boleh pernah mendarat di penyimpanan asli: nomor BAST di database tes mulai dari 1,
     * jadi tes pernah menimpa PDF BAST asli bernomor sama. Disk 'local' & 'public' dipaksa ke folder tes, dan tes ditolak bila masih menunjuk ke penyimpanan asli.
     */
    private function guardRealStorage(): void
    {
        foreach (['local' => storage_path('app/private'), 'public' => storage_path('app/public')] as $disk => $real) {
            config(["filesystems.disks.$disk.root" => storage_path("framework/testing/demo-$disk")]);
            \Illuminate\Support\Facades\Storage::forgetDisk($disk);                 // disk yang sudah terlanjur dibuat dengan root asli dibuang
            $root = rtrim(\Illuminate\Support\Facades\Storage::disk($disk)->path(''), '/');
            if ($root === rtrim($real, '/') || str_starts_with($root . '/', rtrim($real, '/') . '/')) {
                throw new \RuntimeException("Tes menolak jalan: disk '$disk' menunjuk ke penyimpanan asli ($root).");
            }
        }
    }
}
