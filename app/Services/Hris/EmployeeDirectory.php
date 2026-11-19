<?php

namespace App\Services\Hris;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Data karyawan dari HRIS lewat REST baca-saja (GET {HRIS}/api/integrations/employees, token layanan), sama seperti Portal.
 * HRIS yang tidak terjangkau tidak boleh membuat aplikasi ini mati: hasilnya cukup null / kosong, dan salinan terakhir dipakai bila ada.
 */
class EmployeeDirectory
{
    private const KEY = 'asset.hris.employees';
    private const STALE = 'asset.hris.employees.stale';

    public static ?string $lastError = null;

    public function configured(): bool
    {
        return filled(config('services.hris.url')) && filled(config('services.hris.token'));
    }

    public function refresh(): void
    {
        Cache::forget(self::KEY);
        Cache::forget(self::STALE);   // sinkronisasi tidak boleh memakai salinan lama: bisa salah menonaktifkan akun
        self::$lastError = null;
    }

    /** Cari lewat NIK utama ATAU NIK alias. */
    public function find(string $nik): ?object
    {
        $nik = trim($nik);
        $all = $this->all();
        if ($all === null) return null;

        return $all->get($nik) ?? $all->first(fn ($e) => in_array($nik, $e->nik_aliases, true));
    }

    /** @return Collection<string, object>|null dikunci employee_no; null bila HRIS tidak bisa dibaca */
    public function all(): ?Collection
    {
        if (! $this->configured()) {
            self::$lastError = 'HRIS_API_URL / HRIS_API_TOKEN belum diisi.';
            return null;
        }
        if (($hit = Cache::get(self::KEY)) !== null) return $hit;

        try {
            $employees = $this->fetch();
            Cache::put(self::KEY, $employees, (int) config('services.hris.cache_ttl', 300));
            Cache::put(self::STALE, $employees, 86400);
            return $employees;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            Log::warning('API HRIS tidak bisa dibaca: ' . $e->getMessage());
            return Cache::get(self::STALE);
        }
    }

    /** @return Collection<string, object> */
    private function fetch(): Collection
    {
        $url = rtrim((string) config('services.hris.url'), '/') . '/api/integrations/employees';
        $out = collect();
        $page = 1;
        do {
            $res = Http::withToken((string) config('services.hris.token'))->acceptJson()->timeout(15)->connectTimeout(5)
                ->retry(2, 400, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, false)
                ->get($url, ['page' => $page, 'per_page' => 500]);
            if ($res->status() === 401) throw new \RuntimeException('HRIS menolak token (HTTP 401). Samakan HRIS_API_TOKEN dengan token layanan di HRIS.');
            if (! $res->successful()) throw new \RuntimeException("HRIS menjawab HTTP {$res->status()}.");

            foreach ((array) $res->json('data', []) as $row) {
                $nik = trim((string) ($row['employee_no'] ?? ''));
                if ($nik === '') continue;
                $out->put($nik, (object) [
                    'id' => isset($row['id']) ? (int) $row['id'] : null,
                    'employee_no' => $nik,
                    'nik_aliases' => collect((array) ($row['nik_aliases'] ?? []))->map(fn ($a) => trim((string) $a))->filter()->unique()->values()->all(),
                    'name' => (string) ($row['name'] ?? ''),
                    'email' => filled($row['email'] ?? null) ? trim((string) $row['email']) : null,
                    'division' => filled($row['division'] ?? null) ? trim((string) $row['division']) : null,
                    'department' => filled($row['department'] ?? null) ? trim((string) $row['department']) : null,
                    'section' => filled($row['section'] ?? null) ? trim((string) $row['section']) : null,
                    'supervisor_nik' => filled($row['supervisor_nik'] ?? null) ? trim((string) $row['supervisor_nik']) : null,
                    'employment_status' => filled($row['employment_status'] ?? null) ? trim((string) $row['employment_status']) : null,
                    'position' => filled($row['position'] ?? null) ? trim((string) $row['position']) : null,
                    'phone' => filled($row['phone'] ?? null) ? trim((string) $row['phone']) : null,
                    'is_active' => (bool) ($row['is_active'] ?? false),
                ]);
            }
            $last = (int) $res->json('meta.last_page', 1);
        } while (++$page <= $last);

        return $out;
    }
}
