<?php

namespace App\Services\SimaImport;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetModel;
use App\Models\Brand;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Impor data SIMA (Snipe-IT, MariaDB, hanya-baca lewat koneksi "legacy") ke skema aplikasi ini. Idempoten: dijalankan ulang tidak
 * menggandakan data (pemetaan id di legacy_map); aset diperbarui ke keadaan terakhir SIMA, transaksi hanya ditambah yang baru.
 * Aturan yang bergantung pada data produksi ada di config/sima_import.php. Lihat docs/rencana-migrasi-sima.md.
 */
class SimaImporter
{
    public const STEPS = ['departments', 'locations', 'vendors', 'categories', 'models', 'employees', 'assets', 'assignments', 'stock', 'licenses', 'repairs', 'agent', 'files'];
    private const USER = 'App\\Models\\User';
    private const ASSET = 'App\\Models\\Asset';
    private const LOCATION = 'App\\Models\\Location';

    public array $stats = [];
    public array $warnings = [];
    public array $unmatched = [];
    public array $unknownStatuses = [];

    private ConnectionInterface $L;
    private array $modelInfo = [];
    private array $serials = [];
    private ?string $filesPath = null;

    public function __construct(private LegacyMap $map) { $this->L = DB::connection('legacy'); }

    public function setFilesPath(?string $p): void { $this->filesPath = $p ?: config('sima_import.files_path'); }

    public function run(array $only = []): void
    {
        foreach (self::STEPS as $step) {
            if ($only && ! in_array($step, $only, true)) continue;
            $this->{'step' . Str::studly($step)}();
        }
    }

    private function stat(string $k, int $n = 1): void { $this->stats[$k] = ($this->stats[$k] ?? 0) + $n; }
    private function warn(string $m): void { $this->warnings[] = $m; }
    private function dt($v): ?string { return $v ? Carbon::parse($v)->toDateTimeString() : null; }
    private function d($v): ?string { return $v ? Carbon::parse($v)->toDateString() : null; }
    private function s($v): ?string { $v = trim((string) $v); return $v === '' ? null : $v; }
    private function cols(string $table): array { return Schema::getColumnListing($table); }

    // ------------------------------------------------------------------ master

    private function stepDepartments(): void
    {
        foreach ($this->L->table('departments')->orderBy('id')->get() as $r) {
            $name = $this->s($r->name); if (! $name) continue;
            $this->map->put('department', $r->id, $this->deptId($name, $r->deleted_at));
            $this->stat('departments');
        }
    }

    private function deptId(string $name, $deletedAt = null): int
    {
        $id = DB::table('departments')->whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('id');
        return $id ?: DB::table('departments')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $this->dt($deletedAt)]);
    }

    private function stepLocations(): void
    {
        $all = $this->L->table('locations')->get()->keyBy('id');
        $path = function ($r) use ($all) {
            $names = []; $guard = 0;
            for ($x = $r; $x && $guard++ < 10; $x = $x->parent_id ? ($all[$x->parent_id] ?? null) : null) array_unshift($names, trim($x->name));
            return implode(' > ', $names);
        };
        foreach ($all as $r) {
            $name = $path($r);
            $id = DB::table('locations')->whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('id')
                ?: DB::table('locations')->insertGetId(['name' => $name, 'branch' => $this->s($r->city), 'address' => $this->s($r->address), 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $this->dt($r->deleted_at)]);
            $this->map->put('location', $r->id, $id);
            $this->stat('locations');
        }
    }

    private function stepVendors(): void
    {
        foreach ($this->L->table('suppliers')->orderBy('id')->get() as $r) {
            $name = $this->s($r->name); if (! $name) continue;
            $id = DB::table('vendors')->whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('id')
                ?: DB::table('vendors')->insertGetId(['name' => $name, 'contact_person' => $this->s($r->contact), 'phone' => $this->s($r->phone), 'email' => $this->s($r->email), 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $this->dt($r->deleted_at)]);
            $this->map->put('vendor', $r->id, $id);
            $this->stat('vendors');
        }
    }

    private function stepCategories(): void
    {
        foreach ($this->L->table('categories')->orderBy('id')->get() as $r) {
            $type = in_array($r->category_type, ['asset', 'accessory', 'consumable', 'component'], true) ? $r->category_type : null;
            if (! $type) { $this->stat('categories_skipped'); continue; }   // kategori lisensi: tabel lisensi tidak memakai kategori
            $name = $this->s($r->name) ?: 'Tanpa nama';
            $id = DB::table('categories')->whereRaw('lower(name) = ?', [mb_strtolower($name)])->where('type', $type)->value('id');
            if (! $id) {
                $id = DB::table('categories')->insertGetId(['name' => $name, 'type' => $type, 'code_prefix' => $type === 'asset' ? $this->prefixFor($name) : null,
                    'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $this->dt($r->deleted_at)]);
            }
            $this->map->put('category', $r->id, $id);
            $this->stat('categories');
        }
    }

    private function prefixFor(string $name): string
    {
        $low = mb_strtolower($name);
        $base = null;
        foreach (config('sima_import.category_prefix', []) as $kw => $p) if (str_contains($low, $kw)) { $base = $p; break; }
        $base ??= strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'AST', 0, 3));
        $p = $base; $n = 1;
        while (DB::table('categories')->where('code_prefix', $p)->whereRaw('lower(name) <> ?', [$low])->exists()) $p = $base . (++$n);
        return $p;
    }

    private function stepModels(): void
    {
        $brands = [];
        foreach ($this->L->table('manufacturers')->get() as $m) if ($n = $this->s($m->name)) $brands[$m->id] = Brand::resolve($n);
        foreach ($this->L->table('models')->orderBy('id')->get() as $r) {
            $brand = $brands[$r->manufacturer_id] ?? Brand::resolve('Tanpa merek');
            $name = $this->s($r->name) ?: ($this->s($r->model_number) ?: 'Tanpa model');
            $catId = $this->map->get('category', $r->category_id);
            $model = AssetModel::resolve($brand->id, $name, $catId);
            $this->map->put('model', $r->id, $model->id);
            $this->modelInfo[$r->id] = ['brand_id' => $brand->id, 'brand' => $brand->name, 'model' => $model->name, 'model_id' => $model->id, 'category_id' => $catId];
            $this->stat('models');
        }
    }

    private function modelInfo(): array
    {
        if (! $this->modelInfo) {
            foreach ($this->L->table('models')->get() as $r) {
                $b = $r->manufacturer_id ? $this->L->table('manufacturers')->where('id', $r->manufacturer_id)->value('name') : null;
                $this->modelInfo[$r->id] = ['brand_id' => null, 'brand' => $b, 'model' => $r->name, 'model_id' => $this->map->get('model', $r->id), 'category_id' => $this->map->get('category', $r->category_id)];
            }
        }
        return $this->modelInfo;
    }

    // ------------------------------------------------------------------ karyawan

    private function nikOf($u): ?string
    {
        $n = $this->s($u->employee_num);
        if ($n) return $n;
        if (preg_match('/^\d{5,}$/', (string) $u->username)) return (string) $u->username;
        // beberapa akun SIMA menyimpan NIK di nama, mis. "(DADANG SISWANTO) - #32601923"
        foreach ([$u->display_name, $u->first_name, $u->last_name] as $n) if (preg_match('/#\s*(\d{6,})\b/', (string) $n, $m)) return $m[1];
        return null;
    }

    /** Pengguna SIMA yang dirujuk data (pemegang aset, accessories, lisensi, log) -> wajib punya karyawan, walau tanpa NIK. */
    private function referencedUserIds(): array
    {
        $ids = $this->L->table('assets')->where('assigned_type', self::USER)->whereNotNull('assigned_to')->pluck('assigned_to')
            ->merge($this->L->table('accessories_checkout')->where('assigned_type', self::USER)->pluck('assigned_to'))
            ->merge($this->L->table('license_seats')->whereNotNull('assigned_to')->pluck('assigned_to'))
            ->merge($this->L->table('action_logs')->where('item_type', self::ASSET)->where('target_type', self::USER)->pluck('target_id'));
        return $ids->filter()->unique()->flip()->all();
    }

    private function stepEmployees(): void
    {
        $ref = $this->referencedUserIds();
        foreach ($this->L->table('users')->orderBy('id')->get() as $u) {
            $nik = $this->nikOf($u);
            if (! $nik && ! isset($ref[$u->id])) { $this->stat('users_skipped_no_nik_unreferenced'); continue; }

            $name = $this->s($u->display_name) ?: ($this->s(trim($u->first_name . ' ' . $u->last_name)) ?: (string) $u->username);
            $active = (bool) $u->activated && ! $u->deleted_at && (! $u->end_date || Carbon::parse($u->end_date)->isFuture());
            if ($mapped = $this->map->get('employee', $u->id)) {   // impor ulang: jangan buat dobel (terutama yang tanpa NIK); hanya status resign yang diikuti
                if (! $active) DB::table('employees')->where('id', $mapped)->where('status', 'active')->update(['status' => 'resigned', 'updated_at' => now()]);
                $this->stat('employees_already_imported');
                if (! $nik) $this->unmatched[] = ['sima_user_id' => $u->id, 'username' => $u->username, 'name' => $name, 'email' => $u->email, 'reason' => 'Masih tanpa NIK (lengkapi di Karyawan).'];
                continue;
            }
            $emp = $nik ? DB::table('employees')->where('nik', $nik)->first() : null;

            if (! $emp) {
                $email = $this->s($u->email) ?: ($nik ? "nik{$nik}@akp.test" : "sima-user-{$u->id}@akp.test");
                if (DB::table('employees')->where('email', $email)->exists()) $email = ($nik ? "nik{$nik}" : "sima-user-{$u->id}") . '@akp.test';
                $id = DB::table('employees')->insertGetId([
                    'nik' => $nik, 'name' => $name, 'email' => $email, 'position' => $this->s($u->jobtitle), 'phone' => $this->s($u->phone ?: $u->mobile),
                    'department_id' => $u->department_id ? $this->map->get('department', $u->department_id) : null,
                    'status' => $active ? 'active' : 'resigned', 'created_at' => $this->dt($u->created_at) ?: now(), 'updated_at' => now(),
                ]);
                $this->stat('employees_created');
            } else { $id = $emp->id; $this->stat('employees_matched'); }
            $this->map->put('employee', $u->id, $id);

            if (! $nik) {
                $this->unmatched[] = ['sima_user_id' => $u->id, 'username' => $u->username, 'name' => $name, 'email' => $u->email, 'reason' => 'Tanpa NIK (employee_num); dibuat sebagai karyawan tanpa NIK karena masih dirujuk data. Lengkapi NIK-nya.'];
            }
        }
    }

    // ------------------------------------------------------------------ aset

    public function mapStatus(?object $label, bool $held, bool $deleted): string
    {
        $name = mb_strtolower(trim((string) ($label->name ?? '')));
        if (isset(config('sima_import.status_overrides')[$name])) return config('sima_import.status_overrides')[$name];
        $s = match (true) {
            str_contains($name, 'lost') || str_contains($name, 'hilang') => 'lost',
            str_contains($name, 'repair') || str_contains($name, 'perbaik') || str_contains($name, 'servis') || str_contains($name, 'service') || str_contains($name, 'maintenance') => 'repair',
            str_contains($name, 'dispos') || str_contains($name, 'scrap') || str_contains($name, 'dijual') || str_contains($name, 'sold') || str_contains($name, 'rusak berat') => 'disposed',
            str_contains($name, 'borrow') || str_contains($name, 'pinjam') => 'borrowed',
            $label && $label->archived => 'disposed',
            $label && $label->deployable => $held ? 'deployed' : 'available',
            $label && $label->pending => $held ? 'deployed' : 'available',
            default => null,
        };
        if ($s === null) { $this->unknownStatuses[$name ?: '(kosong)'] = ($this->unknownStatuses[$name ?: '(kosong)'] ?? 0) + 1; $s = $held ? 'deployed' : 'available'; }
        return $s === 'available' && $held ? 'deployed' : $s;
    }

    private ?array $cfLabels = null;

    /** db_column -> nama custom field di SIMA (kosong bila tabelnya tidak ada). */
    private function customFieldLabels(): array
    {
        if ($this->cfLabels === null) {
            $this->cfLabels = Schema::connection('legacy')->hasTable('custom_fields') ? $this->L->table('custom_fields')->pluck('name', 'db_column')->all() : [];
        }
        return $this->cfLabels;
    }

    private function stepAssets(): void
    {
        $labels = $this->L->table('status_labels')->get()->keyBy('id');
        $info = $this->modelInfo();
        $uncat = null;
        $this->L->table('assets')->orderBy('id')->chunkById(500, function ($rows) use ($labels, $info, &$uncat) {
            foreach ($rows as $a) {
                $holderUser = ($a->assigned_type === self::USER && $a->assigned_to) ? $a->assigned_to : null;
                $empId = $holderUser ? $this->map->get('employee', $holderUser) : null;
                $held = (bool) $empId;
                $status = $this->mapStatus($labels[$a->status_id] ?? null, $held, (bool) $a->deleted_at);
                if (in_array($status, ['lost', 'disposed', 'repair'], true) && ! $held) $empId = null;
                $locId = $this->map->get('location', $a->rtd_location_id ?: $a->location_id);
                $notes = [$this->s($a->notes)];
                // Status non-standar tetap terlacak: nama status SIMA dicatat; "tidak bisa diperbaiki" -> kondisi Rusak Berat (BUKAN disposed: disposal butuh persetujuan).
                $labelName = trim((string) ($labels[$a->status_id]->name ?? ''));
                $condition = 'good';
                if (in_array($status, ['repair', 'lost', 'disposed'], true) && $labelName !== '') $notes[] = "Status di SIMA: {$labelName}.";
                if (preg_match('/non[\s-]*repair|not\s*fix|broken|rusak\s*berat|unrepair/i', $labelName)) $condition = 'major_damage';
                if ($a->assigned_type === self::LOCATION && $a->assigned_to) { $locId = $this->map->get('location', $a->assigned_to) ?: $locId; $notes[] = 'Sebelumnya dikeluarkan ke lokasi (SIMA).'; }
                if ($a->assigned_type === self::ASSET && $a->assigned_to) { $notes[] = 'Sebelumnya terpasang pada aset SIMA #' . $a->assigned_to . '.'; }
                // custom field Snipe-IT berbeda per instalasi (kolom _snipeit_*): semua nilai terisi dicatat di catatan, tidak dibuang
                foreach (get_object_vars($a) as $col => $val) {
                    if (str_starts_with($col, '_snipeit_') && ($v = $this->s($val))) $notes[] = ($this->customFieldLabels()[$col] ?? ucwords(str_replace('_', ' ', trim(preg_replace('/^_snipeit_|_\d+$/', '', $col), '_')))) . ": $v";
                }

                $newId = $this->map->get('asset', $a->id);
                if ($newId) {   // sudah pernah diimpor: ikuti keadaan terakhir SIMA
                    DB::table('assets')->where('id', $newId)->update(['status' => $status, 'employee_id' => $empId, 'location_id' => $locId, 'updated_at' => now(), 'deleted_at' => $this->dt($a->deleted_at)]);
                    $this->stat('assets_updated');
                    continue;
                }

                $m = $info[$a->model_id] ?? ['brand' => null, 'model' => null, 'model_id' => null, 'category_id' => null, 'brand_id' => null];
                $catId = $m['category_id'] ?: ($uncat ??= DB::table('categories')->where('name', 'Lainnya')->where('type', 'asset')->value('id')
                    ?: DB::table('categories')->insertGetId(['name' => 'Lainnya', 'type' => 'asset', 'code_prefix' => 'AST', 'created_at' => now(), 'updated_at' => now()]));
                if (! $m['category_id']) $this->warn("Aset SIMA #{$a->id} ({$a->asset_tag}) tanpa kategori; dimasukkan ke 'Lainnya'.");
                $brandId = $m['brand_id'] ?: ($m['model_id'] ? DB::table('asset_models')->where('id', $m['model_id'])->value('brand_id') : null);

                $serial = $this->s($a->serial);
                if ($serial) {
                    $k = mb_strtolower($serial);
                    if (isset($this->serials[$k]) || DB::table('assets')->whereRaw('lower(serial_number) = ?', [$k])->exists() || DB::table('components')->whereRaw('lower(serial_number) = ?', [$k])->exists()) {
                        $this->warn("Serial duplikat '{$serial}' pada aset SIMA {$a->asset_tag}: dikosongkan di aplikasi baru dan dicatat di catatan aset.");
                        $notes[] = "Serial di SIMA: {$serial} (duplikat, dikosongkan saat impor).";
                        $serial = null;
                    } else $this->serials[$k] = true;
                }

                $legacyTag = trim((string) $a->asset_tag);
                $keep = preg_match(config('sima_import.keep_tag_pattern'), $legacyTag) && ! DB::table('assets')->where('asset_tag', $legacyTag)->exists();
                $prefix = DB::table('categories')->where('id', $catId)->value('code_prefix') ?: 'AST';
                $tag = $keep ? $legacyTag : Asset::nextTag(Asset::tagPrefix(), $prefix);

                $purchase = $this->d($a->purchase_date);
                $warranty = ($purchase && $a->warranty_months) ? Carbon::parse($purchase)->addMonths((int) $a->warranty_months)->toDateString() : null;
                $newId = DB::table('assets')->insertGetId([
                    'asset_tag' => $tag, 'legacy_tag' => $keep ? null : ($legacyTag ?: null), 'category_id' => $catId, 'brand' => $m['brand'], 'model' => $m['model'], 'brand_id' => $brandId, 'asset_model_id' => $m['model_id'],
                    'serial_number' => $serial, 'purchase_date' => $purchase, 'vendor_id' => $this->map->get('vendor', $a->supplier_id), 'po_pr_no' => $this->s($a->order_number),
                    'purchase_price' => (float) $a->purchase_cost, 'warranty_until' => $warranty, 'employee_id' => $empId, 'location_id' => $locId, 'status' => $status,
                    'condition' => $condition, 'notes' => implode("\n", array_filter($notes)) ?: null, 'ownership' => 'owned',
                    'spec_data' => ($n = $this->s($a->name)) ? json_encode(['name' => $n]) : null,
                    'agent_status' => in_array($a->agent_status, ['online', 'offline', 'never_reported'], true) ? $a->agent_status : 'never_reported', 'agent_last_seen' => $this->dt($a->agent_last_seen),
                    'created_at' => $this->dt($a->created_at) ?: now(), 'updated_at' => $this->dt($a->updated_at) ?: now(), 'deleted_at' => $this->dt($a->deleted_at),
                ]);
                if (! $keep && $legacyTag !== '') DB::table('asset_tag_aliases')->insert(['asset_id' => $newId, 'tag' => $legacyTag, 'created_at' => now(), 'updated_at' => now()]);
                $this->map->put('asset', $a->id, $newId);
                $this->stat('assets_created');
            }
        }, 'id');
    }

    // ------------------------------------------------------------------ riwayat serah terima

    private function stepAssignments(): void
    {
        $bast = $this->L->table('bast_histories')->orderBy('id')->get()->groupBy('asset_id');
        $logs = $this->L->table('action_logs')->where('item_type', self::ASSET)->whereIn('action_type', ['checkout', 'checkin from'])->orderBy('id')->get()->groupBy('item_id');
        foreach ($logs as $legacyAssetId => $items) {
            $assetId = $this->map->get('asset', $legacyAssetId);
            if (! $assetId) continue;
            $last = DB::table('asset_assignments')->where('asset_id', $assetId)->orderByDesc('assigned_at')->orderByDesc('id')->first(['type', 'to_employee_id']);
            $cur = $last && $last->type !== 'checkin' ? $last->to_employee_id : null;

            foreach ($items as $log) {
                if ($this->map->get('actionlog', $log->id)) continue;
                $emp = ($log->target_type === self::USER && $log->target_id) ? $this->map->get('employee', $log->target_id) : null;
                $at = $this->dt($log->action_date ?: $log->created_at) ?: now()->toDateTimeString();
                if ($log->action_type === 'checkout') {
                    if (! $emp) { $this->warn("Log checkout #{$log->id} aset SIMA {$legacyAssetId} tanpa pemegang yang dikenal; dilewati."); continue; }
                    $type = $cur ? 'transfer' : 'checkout'; $from = $cur; $to = $emp; $cur = $emp;
                } else {
                    if (! $cur) continue;   // checkin tanpa pemegang tercatat
                    $type = 'checkin'; $from = $cur; $to = null; $cur = null;
                }
                $b = $this->pickBast($bast[$legacyAssetId] ?? collect(), $at);
                $num = $b->document_number ?? null;
                if (! $num || DB::table('asset_assignments')->where('bast_number', $num)->exists()) $num = AssetAssignment::LEGACY_NUMBER_PREFIX . 'SIMA-' . $log->id;
                $id = DB::table('asset_assignments')->insertGetId([
                    'asset_id' => $assetId, 'type' => $type, 'from_employee_id' => $from, 'to_employee_id' => $to, 'location_id' => $this->map->get('location', $log->location_id),
                    'condition' => 'good', 'bast_number' => $num, 'notes' => $this->s($log->note) ? 'SIMA: ' . $this->s($log->note) : 'Diimpor dari SIMA',
                    'assigned_at' => $at, 'created_at' => $at, 'updated_at' => now(),
                ]);
                $this->map->put('actionlog', $log->id, $id);
                if ($b) $this->map->put('bast_history', $b->id, $id);
                $this->stat('assignments_created');
            }
        }

        // Kesesuaian: pemegang di tabel aset (kebenaran SIMA) harus sama dengan hasil riwayat; selisih ditutup dengan satu baris sinkron.
        DB::table('assets')->whereNull('deleted_at')->orderBy('id')->each(function ($a) {
            $last = DB::table('asset_assignments')->where('asset_id', $a->id)->orderByDesc('assigned_at')->orderByDesc('id')->first(['type', 'to_employee_id']);
            $hist = $last && $last->type !== 'checkin' ? (int) $last->to_employee_id : null;
            if ($hist === (int) $a->employee_id || $a->employee_id === null && $hist === null) return;
            $type = $a->employee_id ? ($hist ? 'transfer' : 'checkout') : 'checkin';
            DB::table('asset_assignments')->insert([
                'asset_id' => $a->id, 'type' => $type, 'from_employee_id' => $hist ?: null, 'to_employee_id' => $a->employee_id, 'condition' => 'good',
                'bast_number' => AssetAssignment::LEGACY_NUMBER_PREFIX . 'SIMA-SYNC-' . $a->id . '-' . now()->format('YmdHis'), 'notes' => 'Penyesuaian saldo saat impor SIMA (riwayat SIMA tidak lengkap).',
                'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->stat('assignments_balancing');
            $this->warn("Aset {$a->asset_tag}: riwayat serah terima SIMA tidak sesuai pemegang saat ini; ditambahkan baris penyesuaian.");
        });
    }

    private function pickBast($list, string $at): ?object
    {
        $t = Carbon::parse($at);
        return $list->sortBy(fn ($b) => abs(Carbon::parse($b->created_at)->diffInSeconds($t, false)))->first(fn ($b) => abs(Carbon::parse($b->created_at)->diffInHours($t, false)) <= 36);
    }

    // ------------------------------------------------------------------ stok

    private function movement(string $type, string $model, int $id, int $qty, array $extra = []): void
    {
        DB::table('stock_movements')->insert($extra + ['stockable_type' => $model, 'stockable_id' => $id, 'type' => $type, 'qty' => $qty, 'moved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function stepStock(): void
    {
        // accessories
        $out = $this->L->table('accessories_checkout')->get()->groupBy('accessory_id');
        foreach ($this->L->table('accessories')->orderBy('id')->get() as $r) {
            if ($this->map->get('accessory', $r->id)) continue;
            $held = ($out[$r->id] ?? collect())->where('assigned_type', self::USER);
            $total = max((int) $r->qty, 0);   // Snipe: accessories.qty = jumlah TOTAL; baris checkout = yang sedang dipegang
            $avail = max($total - $held->count(), 0);
            $brand = $r->manufacturer_id ? $this->L->table('manufacturers')->where('id', $r->manufacturer_id)->value('name') : null;
            $id = DB::table('accessories')->insertGetId([
                'name' => $this->s($r->name) ?: 'Tanpa nama', 'category_id' => $this->map->get('category', $r->category_id) ?: $this->fallbackCategory('accessory'),
                'brand_model' => trim($brand . ' ' . $r->model_number) ?: null, 'qty_total' => $total, 'qty_available' => $avail, 'min_qty' => (int) $r->min_amt,
                'location_id' => $this->map->get('location', $r->location_id), 'unit_price' => (float) $r->purchase_cost, 'vendor_id' => $this->map->get('vendor', $r->supplier_id),
                'created_at' => $this->dt($r->created_at) ?: now(), 'updated_at' => now(), 'deleted_at' => $this->dt($r->deleted_at),
            ]);
            $this->map->put('accessory', $r->id, $id);
            if ($total > 0) $this->movement('in', \App\Models\Accessory::class, $id, $total, ['reason' => 'Saldo awal (impor SIMA)']);
            foreach ($held as $c) {
                if ($e = $this->map->get('employee', $c->assigned_to)) $this->movement('out', \App\Models\Accessory::class, $id, 1, ['employee_id' => $e, 'reason' => 'Dipegang karyawan (impor SIMA)', 'moved_at' => $this->dt($c->created_at) ?: now()]);
            }
            $this->stat('accessories');
        }
        // consumables: saldo saat ini
        foreach ($this->L->table('consumables')->orderBy('id')->get() as $r) {
            if ($this->map->get('consumable', $r->id)) continue;
            $qty = max((int) $r->qty, 0);
            $id = DB::table('consumables')->insertGetId([
                'name' => $this->s($r->name) ?: 'Tanpa nama', 'category_id' => $this->map->get('category', $r->category_id) ?: $this->fallbackCategory('consumable'), 'unit' => 'pcs',
                'qty' => $qty, 'min_qty' => (int) $r->min_amt, 'location_id' => $this->map->get('location', $r->location_id), 'unit_price' => (float) $r->purchase_cost,
                'vendor_id' => $this->map->get('vendor', $r->supplier_id), 'created_at' => $this->dt($r->created_at) ?: now(), 'updated_at' => now(), 'deleted_at' => $this->dt($r->deleted_at),
            ]);
            $this->map->put('consumable', $r->id, $id);
            if ($qty > 0) $this->movement('in', \App\Models\Consumable::class, $id, $qty, ['reason' => 'Saldo awal (impor SIMA)']);
            $this->stat('consumables');
        }
        // components + yang terpasang di aset
        $inst = $this->L->table('components_assets')->get()->groupBy('component_id');
        foreach ($this->L->table('components')->orderBy('id')->get() as $r) {
            if ($this->map->get('component', $r->id)) continue;
            $total = max((int) $r->qty, 0);
            $installed = ($inst[$r->id] ?? collect())->sum('assigned_qty');
            $serial = $this->s($r->serial);
            if ($serial) {
                $k = mb_strtolower($serial);
                if (isset($this->serials[$k]) || DB::table('components')->whereRaw('lower(serial_number) = ?', [$k])->exists() || DB::table('assets')->whereRaw('lower(serial_number) = ?', [$k])->exists()) { $this->warn("Serial duplikat '{$serial}' pada komponen SIMA '{$r->name}': dikosongkan."); $serial = null; }
                else $this->serials[$k] = true;
            }
            $id = DB::table('components')->insertGetId([
                'name' => $this->s($r->name) ?: 'Tanpa nama', 'category_id' => $this->map->get('category', $r->category_id) ?: $this->fallbackCategory('component'),
                'serial_number' => $serial, 'qty_total' => $total, 'qty_available' => max($total - $installed, 0), 'min_qty' => (int) $r->min_amt,
                'location_id' => $this->map->get('location', $r->location_id), 'price' => (float) $r->purchase_cost, 'vendor_id' => $this->map->get('vendor', $r->supplier_id),
                'created_at' => $this->dt($r->created_at) ?: now(), 'updated_at' => now(), 'deleted_at' => $this->dt($r->deleted_at),
            ]);
            $this->map->put('component', $r->id, $id);
            if ($total > 0) $this->movement('in', \App\Models\Component::class, $id, $total, ['reason' => 'Saldo awal (impor SIMA)']);
            foreach ($inst[$r->id] ?? [] as $ci) {
                if ($assetId = $this->map->get('asset', $ci->asset_id)) $this->movement('install', \App\Models\Component::class, $id, (int) $ci->assigned_qty, ['asset_id' => $assetId, 'reason' => 'Terpasang di aset (impor SIMA)', 'moved_at' => $this->dt($ci->created_at) ?: now()]);
            }
            $this->stat('components');
        }
    }

    private function fallbackCategory(string $type): int
    {
        return DB::table('categories')->where('type', $type)->where('name', 'Lainnya')->value('id')
            ?: DB::table('categories')->insertGetId(['name' => 'Lainnya', 'type' => $type, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ------------------------------------------------------------------ lisensi, perbaikan

    private function stepLicenses(): void
    {
        foreach ($this->L->table('licenses')->orderBy('id')->get() as $r) {
            if ($this->map->get('license', $r->id)) continue;
            $id = DB::table('licenses')->insertGetId([
                'name' => $this->s($r->name) ?: 'Tanpa nama', 'type' => $r->expiration_date ? 'subscription' : 'perpetual', 'license_key' => $this->s($r->serial), 'seats' => max((int) $r->seats, 0),
                'purchase_date' => $this->d($r->purchase_date), 'expires_at' => $this->d($r->expiration_date), 'price' => (float) $r->purchase_cost, 'vendor_id' => $this->map->get('vendor', $r->supplier_id),
                'notes' => $this->s($r->notes), 'created_at' => $this->dt($r->created_at) ?: now(), 'updated_at' => now(), 'deleted_at' => $this->dt($r->deleted_at),
            ]);
            $this->map->put('license', $r->id, $id);
            $this->stat('licenses');
            foreach ($this->L->table('license_seats')->where('license_id', $r->id)->whereNull('deleted_at')->get() as $s) {
                $emp = $s->assigned_to ? $this->map->get('employee', $s->assigned_to) : null; $asset = $s->asset_id ? $this->map->get('asset', $s->asset_id) : null;
                if (! $emp && ! $asset) continue;
                DB::table('license_seats')->insert(['license_id' => $id, 'employee_id' => $emp, 'asset_id' => $asset, 'assigned_at' => $this->dt($s->updated_at ?: $s->created_at) ?: now(), 'created_at' => now(), 'updated_at' => now()]);
                $this->stat('license_seats');
            }
        }
    }

    private function stepRepairs(): void
    {
        foreach ($this->L->table('maintenances')->orderBy('id')->get() as $r) {
            if ($this->map->get('repair', $r->id)) continue;
            $assetId = $this->map->get('asset', $r->asset_id);
            if (! $assetId) { $this->warn("Maintenance SIMA #{$r->id} merujuk aset yang tidak ada; dilewati."); continue; }
            $vendor = $r->supplier_id ? $this->L->table('suppliers')->where('id', $r->supplier_id)->value('name') : null;
            $id = DB::table('repairs')->insertGetId([
                'asset_id' => $assetId, 'reported_at' => $this->d($r->start_date) ?: now()->toDateString(), 'issue' => trim(($r->asset_maintenance_type ? $r->asset_maintenance_type . ': ' : '') . $r->name),
                'action' => $this->s($r->notes), 'cost' => (float) $r->cost, 'technician_vendor' => $vendor, 'warranty_claim' => (bool) $r->is_warranty,
                'status' => $r->completion_date ? 'done' : 'in_progress', 'finished_at' => $this->d($r->completion_date), 'created_at' => $this->dt($r->created_at) ?: now(), 'updated_at' => now(),
            ]);
            $this->map->put('repair', $r->id, $id);
            $this->stat('repairs');
        }
    }

    // ------------------------------------------------------------------ agent

    /** Salin tabel agent apa adanya (kolom yang ada di kedua sisi). Token: hash dan lookup dipertahankan, TIDAK dibuat ulang. */
    private function copyAgent(string $table, array $assetCols = ['asset_id'], ?callable $filter = null, array $nullCols = ['created_by', 'requested_by', 'user_id', 'started_by', 'closed_by'], ?string $entity = null, array $mapCols = []): void
    {
        if (! Schema::connection('legacy')->hasTable($table) || ! Schema::hasTable($table)) return;
        $target = $this->cols($table);
        $hasId = Schema::connection('legacy')->hasColumn($table, 'id');
        if (! $hasId) $entity = null;   // tabel penghubung tanpa id: tidak ada pemetaan; dedupe lewat kunci unik di tujuan
        foreach ($hasId ? $this->L->table($table)->orderBy('id')->get() : $this->L->table($table)->get() as $row) {
            $row = (array) $row; $legacyId = $row['id'] ?? null;
            if ($entity && $this->map->get($entity, $legacyId)) continue;
            if ($filter && ! $filter($row)) continue;
            $skip = false;
            foreach ($assetCols as $c) { if (! array_key_exists($c, $row)) continue; $row[$c] = $this->map->get('asset', $row[$c]); if (! $row[$c]) $skip = true; }
            foreach ($mapCols as $c => $ent) { $row[$c] = $this->map->get($ent, $row[$c]); if (! $row[$c]) $skip = true; }
            if ($skip) continue;
            foreach ($nullCols as $c) if (array_key_exists($c, $row)) $row[$c] = null;
            unset($row['id']);
            $row = array_intersect_key($row, array_flip($target));
            if ($table === 'asset_heartbeats') DB::table($table)->updateOrInsert(['asset_id' => $row['asset_id']], $row);
            elseif ($table === 'asset_helper_tokens') DB::table($table)->updateOrInsert(['asset_id' => $row['asset_id']], $row);
            elseif (! $hasId) DB::table($table)->insertOrIgnore($row);
            else { $nid = DB::table($table)->insertGetId($row); if ($entity) $this->map->put($entity, $legacyId, $nid); }
            $this->stat("agent_$table");
        }
    }

    private function stepAgent(): void
    {
        $this->copyAgent('asset_agent_tokens', entity: 'agent_token');
        $this->copyAgent('asset_helper_tokens');
        $this->copyAgent('asset_heartbeats');
        $this->copyAgent('asset_spec_changes', entity: 'agent_spec_change');
        $this->copyAgent('asset_agent_commands', filter: fn ($r) => in_array($r['status'], ['pending', 'sent'], true), entity: 'agent_command');   // riwayat perintah lama tidak dibawa
        $this->copyAgent('agent_announcements', assetCols: [], entity: 'agent_announcement');
        $this->copyAgent('agent_announcement_targets', mapCols: ['announcement_id' => 'agent_announcement'], entity: 'agent_ann_target');
        $this->copyAgent('agent_announcement_deliveries', mapCols: ['announcement_id' => 'agent_announcement'], entity: 'agent_ann_delivery');
        $this->copyAgent('agent_chat_sessions', entity: 'agent_chat_session');
        $this->copyAgent('agent_chat_messages', assetCols: [], mapCols: ['chat_session_id' => 'agent_chat_session'], entity: 'agent_chat_message');
        $this->copyAgent('agent_rollout_snapshots', assetCols: [], entity: 'agent_rollout');
    }

    // ------------------------------------------------------------------ berkas

    private function stepFiles(): void
    {
        $root = $this->filesPath;
        if (! $root || ! is_dir($root)) { $this->warn('Berkas (foto aset, PDF BAST) tidak disalin: --files=<folder berkas SIMA> tidak diberikan / tidak ada.'); return; }
        $find = function (string $rel) use ($root) {
            foreach (['', '/public/uploads', '/uploads', '/private_uploads', '/storage/private_uploads', '/storage/app'] as $base) {
                $p = rtrim($root, '/') . $base . '/' . ltrim($rel, '/');
                if (is_file($p)) return $p;
            }
            return null;
        };
        foreach ($this->L->table('assets')->whereNotNull('image')->where('image', '<>', '')->get(['id', 'image']) as $a) {
            $id = $this->map->get('asset', $a->id); if (! $id || DB::table('assets')->where('id', $id)->whereNotNull('photo_path')->exists()) continue;
            $src = $find('assets/' . $a->image);
            if (! $src) { $this->stat('files_missing'); continue; }
            $dest = 'assets/photos/legacy-' . $id . '-' . basename($src);
            Storage::disk('public')->put($dest, file_get_contents($src));
            DB::table('assets')->where('id', $id)->update(['photo_path' => $dest]);
            $this->stat('files_photos');
        }
        foreach ($this->L->table('bast_histories')->whereNotNull('pdf_path')->get() as $b) {
            $asg = $this->map->get('bast_history', $b->id); if (! $asg) continue;
            $src = $find($b->pdf_path);
            if (! $src) { $this->stat('files_missing'); continue; }
            $dest = 'bast/legacy/' . $asg . '-' . basename($src);
            Storage::disk('local')->put($dest, file_get_contents($src));
            DB::table('asset_assignments')->where('id', $asg)->update(['bast_pdf_path' => $dest]);
            $this->stat('files_bast');
        }
    }
}
