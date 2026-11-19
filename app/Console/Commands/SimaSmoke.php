<?php

namespace App\Console\Commands;

use App\Services\Hris\EmployeeDirectory;
use App\Services\AgentUpdateManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

/** Uji asap setelah deploy / cutover (runbook docs/runbook-cutover-sima.md). Exit 1 bila ada yang GAGAL; PERINGATAN tidak menggagalkan. */
class SimaSmoke extends Command
{
    protected $signature = 'sima:smoke {--skip-network : Lewati pemeriksaan ke Portal/HRIS (tanpa jaringan)}';
    protected $description = 'Uji asap pasca-deploy: database, peran, SSO Portal, HRIS, API untuk HRIS, agent, dan kebersihan produksi.';

    private array $rows = [];

    private function check(string $name, string $level, string $detail = ''): void { $this->rows[] = [$name, $level, $detail]; }

    public function handle(): int
    {
        try { DB::select('select 1'); $this->check('Database terhubung', 'OK', DB::getDriverName()); }
        catch (\Throwable $e) { $this->check('Database terhubung', 'GAGAL', $e->getMessage()); return $this->finish(); }

        $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))->keys()->diff(DB::table('migrations')->pluck('migration'))->count();
        $this->check('Migrasi terbaru', $pending === 0 ? 'OK' : 'GAGAL', $pending ? "{$pending} migrasi belum dijalankan" : '');

        $roles = Role::whereIn('name', ['Admin', 'Staff IT', 'User'])->count();
        $this->check('Peran Admin / Staff IT / User', $roles === 3 ? 'OK' : 'GAGAL', $roles === 3 ? '' : 'jalankan: php artisan db:seed --class=Database\\\\Seeders\\\\RolesSeeder');

        $demo = DB::table('users')->where('email', 'like', '%@inventaris.test')->count();
        $this->check('Tidak ada akun demo', $demo === 0 ? 'OK' : (app()->isProduction() ? 'GAGAL' : 'PERINGATAN'), $demo ? "{$demo} akun @inventaris.test (password bawaan terkenal) harus dihapus" : '');

        if (app()->isProduction()) {
            $this->check('APP_DEBUG mati', config('app.debug') ? 'GAGAL' : 'OK');
            $this->check('APP_URL https', str_starts_with((string) config('app.url'), 'https://') ? 'OK' : 'PERINGATAN', (string) config('app.url'));
        }

        // SSO
        if (! config('sso.enabled')) $this->check('SSO Portal aktif', 'PERINGATAN', 'SSO_ENABLED=false: login masih NIK + password lokal');
        else {
            $ok = filled(config('sso.secret')) && filled(config('sso.app_code'));
            $this->check('SSO terkonfigurasi (kode modul + rahasia)', $ok ? 'OK' : 'GAGAL', (string) config('sso.app_code'));
            $this->check('Login darurat lokal', config('sso.allow_local_login') ? 'PERINGATAN' : 'OK', config('sso.allow_local_login') ? 'SSO_ALLOW_LOCAL_LOGIN masih true: matikan setelah serah-terima' : 'mati');
            if (! $this->option('skip-network')) {
                try { $r = Http::timeout(6)->get(rtrim((string) config('sso.portal_url'), '/') . '/login'); $this->check('Portal terjangkau', $r->status() < 500 ? 'OK' : 'GAGAL', 'HTTP ' . $r->status()); }
                catch (\Throwable $e) { $this->check('Portal terjangkau', 'GAGAL', $e->getMessage()); }
            }
        }

        // HRIS
        $hris = app(EmployeeDirectory::class);
        if (! $hris->configured()) $this->check('HRIS terhubung', 'PERINGATAN', 'HRIS_API_URL / HRIS_API_TOKEN kosong: sinkron karyawan mati');
        elseif (! $this->option('skip-network')) {
            $hris->refresh(); $all = $hris->all();
            $this->check('HRIS terhubung', $all ? 'OK' : 'GAGAL', $all ? $all->count() . ' karyawan terbaca' : (string) EmployeeDirectory::$lastError);
        }

        if (\App\Support\Hris::managed()) {
            $missing = DB::table('employees')->whereNotNull('hris_missing_at')->whereNull('deleted_at')->count();
            $never = DB::table('employees')->whereNull('hris_synced_at')->whereNull('hris_missing_at')->whereNull('deleted_at')->count();
            $this->check('Karyawan semuanya dari HRIS', ($missing + $never) === 0 ? 'OK' : 'PERINGATAN', ($missing + $never) === 0 ? '' : "{$missing} tidak ada di HRIS, {$never} belum pernah sinkron: jalankan hris:sync-employees --apply dan putuskan sisanya (filter Sumber data di menu Karyawan)");
            $this->check('Department dari HRIS', DB::table('departments')->whereNotNull('hris_synced_at')->exists() ? 'OK' : 'PERINGATAN', 'belum ada department hasil sinkron HRIS');
        }

        // API untuk HRIS + agent
        $this->check('Token API untuk HRIS (SIMA_API_TOKEN)', filled(config('services.integration_token')) ? 'OK' : 'PERINGATAN', filled(config('services.integration_token')) ? '' : 'kosong: panel aset di offboarding HRIS mati');
        $m = app(AgentUpdateManifest::class)->read();
        $this->check('Paket update agent terbaca', $m ? 'OK' : 'PERINGATAN', $m ? 'versi ' . $m['version'] : 'agent-updates/manifest.json tidak ada: update otomatis agent mati');
        $this->check('Tabel agent ada', collect(['asset_agent_tokens', 'asset_heartbeats'])->every(fn ($t) => \Illuminate\Support\Facades\Schema::hasTable($t)) ? 'OK' : 'GAGAL');
        $tokens = DB::table('asset_agent_tokens')->whereNull('revoked_at')->count();
        $this->check('Token agent aktif', $tokens > 0 ? 'OK' : 'PERINGATAN', "{$tokens} token" . ($tokens ? '' : ' (belum ada: impor SIMA belum dijalankan?)'));
        $recent = DB::table('assets')->where('agent_status', 'online')->count();
        $this->check('Agent online', 'OK', "{$recent} perangkat (naik bertahap setelah cutover)");

        $this->check('Antrian & penjadwal', config('queue.default') === 'sync' ? 'PERINGATAN' : 'OK', 'QUEUE_CONNECTION=' . config('queue.default') . ' (pastikan kontainer queue & scheduler jalan)');
        $this->check('Storage dapat ditulis', is_writable(storage_path('app')) ? 'OK' : 'GAGAL');

        return $this->finish();
    }

    private function finish(): int
    {
        $this->table(['Pemeriksaan', 'Hasil', 'Keterangan'], $this->rows);
        $fail = collect($this->rows)->where(1, 'GAGAL')->count();
        $warn = collect($this->rows)->where(1, 'PERINGATAN')->count();
        $fail ? $this->error("{$fail} GAGAL, {$warn} peringatan.") : $this->info("Lulus ({$warn} peringatan).");
        return $fail ? self::FAILURE : self::SUCCESS;
    }
}
