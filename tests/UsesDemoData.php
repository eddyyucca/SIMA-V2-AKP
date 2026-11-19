<?php

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Seed data demo lengkap SEKALI per proses (di luar transaksi), lalu tiap tes berjalan dalam transaksi yang di-rollback.
 * File (foto, dokumen, BAST) ditulis ke direktori testing terpisah agar storage asli tidak tersentuh.
 */
trait UsesDemoData
{
    use RefreshDatabase;

    protected function refreshTestDatabase(): void
    {
        foreach (['local', 'public'] as $d) {
            config(["filesystems.disks.$d.root" => storage_path("framework/testing/demo-$d" . TestCase::workerSuffix())]);
        }
        if (! DemoState::$seeded) {
            foreach (['local', 'public'] as $d) (new Filesystem)->deleteDirectory(storage_path("framework/testing/demo-$d" . TestCase::workerSuffix()));
            $this->migrateDatabasesWithoutEvents();
            $this->app[\Illuminate\Contracts\Console\Kernel::class]->setArtisan(null);
            $this->updateLocalCacheOfInMemoryDatabases();
            RefreshDatabaseState::$migrated = true;
            $this->seed(DatabaseSeeder::class);
            $this->seed(DemoDataSeeder::class);
            DemoState::$seeded = true;
        }
        $this->beginDatabaseTransaction();
    }
}
