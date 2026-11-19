<?php

namespace Tests\Qa;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\QaTestCase;

/** Instalasi baru / database dikosongkan: semua halaman harus tetap terbuka (tanpa error 500). */
class Qa59_EmptyDatabaseTest extends QaTestCase
{
    public function test_every_page_opens_with_an_empty_database(): void
    {
        $admin = $this->admin();
        $keep = ['migrations', 'permissions', 'roles', 'role_has_permissions', 'model_has_permissions', 'model_has_roles', 'settings', 'users', 'sessions'];
        if (DB::getDriverName() === 'pgsql') {
            // PostgreSQL: kosongkan semua tabel sekaligus (CASCADE) tanpa menonaktifkan constraint
            $tables = collect(DB::select("select tablename from pg_tables where schemaname = current_schema()"))->pluck('tablename')
                ->reject(fn ($t) => in_array($t, $keep, true))->map(fn ($t) => '"' . $t . '"')->implode(', ');
            DB::statement("TRUNCATE TABLE {$tables} RESTART IDENTITY CASCADE");
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $row) {
                $t = array_values((array) $row)[0];
                if (! in_array($t, $keep, true)) DB::table($t)->delete();
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        $this->as($admin);

        $skip = ['admin/login', 'admin/opname-session', 'admin/proses-request', 'requisition/settings/reports/weekly'];
        $failed = [];
        foreach (Route::getRoutes() as $r) {
            $uri = $r->uri();
            if (! in_array('GET', $r->methods(), true) || str_contains($uri, '{')) continue;
            if (! (str_starts_with($uri, 'admin') || str_starts_with($uri, 'requisition'))) continue;
            if (in_array($uri, $skip, true)) continue;
            $status = $this->get('/' . $uri)->getStatusCode();
            if ($status >= 500) $failed[] = "{$uri} -> {$status}";
        }
        $this->assertSame([], $failed, implode("\n", $failed));
    }
}
