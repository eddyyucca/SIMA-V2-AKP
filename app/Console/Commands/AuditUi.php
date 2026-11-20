<?php

namespace App\Console\Commands;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Console\Command;
use Livewire\Livewire;

/**
 * Audit UI terhadap DATA NYATA (hanya membaca; tidak menyimpan/menghapus apa pun): untuk setiap halaman daftar
 *  - pencarian: nilai nyata dari tiap kolom yang bisa dicari harus menemukan barisnya (huruf asli, besar, kecil), karakter khusus tidak error
 *  - urut: setiap kolom bisa diurutkan naik/turun
 *  - filter: setiap opsi dijalankan; filter atribut dibandingkan dengan hitungan langsung dari database
 *  - tampilan: setiap kolom dirender untuk baris-baris nyata
 *  - halaman buat/lihat/ubah dan halaman khusus bisa dibuka dengan data nyata
 * Jalankan: php artisan sima:audit-ui [--only=Assets,Employees] [--sample=12]
 */
class AuditUi extends Command
{
    protected $signature = 'sima:audit-ui {--only= : nama resource dipisah koma} {--sample=12 : jumlah baris contoh per halaman} {--json= : simpan hasil ke berkas JSON}';
    protected $description = 'Audit pencarian, urut, filter, tampilan kolom, dan halaman pada data nyata (hanya membaca)';

    /** @var list<string> */
    private array $problems = [];
    /** @var list<string> */
    private array $notes = [];

    public function handle(): int
    {
        $user = User::role('Admin')->first() ?? User::first();
        if (! $user) { $this->error('Tidak ada pengguna.'); return self::FAILURE; }
        auth()->login($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $only = collect(explode(',', (string) $this->option('only')))->map(fn ($s) => trim($s))->filter()->all();
        $sample = max(3, (int) $this->option('sample'));
        $stats = ['resources' => 0, 'search' => 0, 'search_case' => 0, 'special' => 0, 'sort' => 0, 'filters' => 0, 'filter_options' => 0, 'cells' => 0, 'pages' => 0];

        foreach (Filament::getResources() as $resource) {
            $name = class_basename($resource);
            $short = str_replace('Resource', '', $name);
            if ($only && ! in_array($short, $only, true) && ! in_array($name, $only, true)) continue;
            $stats['resources']++;
            $this->line("== {$short}");
            $this->auditList($resource, $short, $sample, $stats);
            $this->auditPages($resource, $short, $stats);
            $this->auditGlobalSearch($resource, $short);
        }

        if (! $only) {
            foreach (Filament::getPages() as $page) {
                $short = class_basename($page);
                $this->attempt("Halaman {$short}", function () use ($page, &$stats) { Livewire::test($page); $stats['pages']++; });
            }
        }

        $this->newLine();
        $this->info('Ringkasan: ' . json_encode($stats));
        foreach ($this->notes as $n) $this->line('  catatan: ' . $n);
        if ($this->problems) {
            $this->error(count($this->problems) . ' masalah:');
            foreach ($this->problems as $p) $this->line('  - ' . $p);
        } else {
            $this->info('Tidak ada masalah.');
        }
        if ($path = $this->option('json')) file_put_contents($path, json_encode(['stats' => $stats, 'problems' => $this->problems, 'notes' => $this->notes], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $this->problems ? self::FAILURE : self::SUCCESS;
    }

    private function problem(string $m): void { $this->problems[] = $m; $this->line('  ✗ ' . $m); }

    private function attempt(string $label, \Closure $fn): void
    {
        try { $fn(); } catch (\Throwable $e) { $this->problem($label . ': ' . get_class($e) . ' ' . substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 220)); }
    }

    private function auditList(string $resource, string $short, int $sample, array &$stats): void
    {
        $index = $resource::getPages()['index'] ?? null;
        if (! $index) return;
        $class = $index->getPage();
        if (! is_subclass_of($class, ListRecords::class)) return;

        try { $t = Livewire::test($class); } catch (\Throwable $e) { $this->problem("{$short}: halaman daftar tidak terbuka: " . substr($e->getMessage(), 0, 200)); return; }

        $table = $t->instance()->getTable();
        $baseCount = null;
        $this->attempt("{$short} hitung dasar", function () use ($t, &$baseCount) { $baseCount = $t->instance()->getFilteredTableQuery()->count(); });
        $this->line("   baris: " . ($baseCount ?? '?'));

        // ---- baris contoh
        $records = collect();
        $this->attempt("{$short} muat baris", function () use ($t, &$records, $sample) { $r = $t->instance()->getTableRecords();
            $records = collect($r instanceof \Illuminate\Contracts\Pagination\Paginator || $r instanceof \Illuminate\Contracts\Pagination\CursorPaginator ? $r->items() : $r->all())->take($sample)->values(); });

        // ---- tampilan setiap kolom pada baris nyata
        foreach ($table->getColumns() as $col) {
            foreach ($records as $rec) {
                try {
                    $c = (clone $col)->record($rec);
                    $c->getState();
                    if (method_exists($c, 'getFormattedState')) $c->getFormattedState();
                    $stats['cells']++;
                } catch (\Throwable $e) {
                    $this->problem("{$short} kolom '{$col->getName()}' gagal tampil (baris #{$rec->getKey()}): " . substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 180));
                    break;
                }
            }
        }

        // ---- urut
        foreach ($table->getColumns() as $col) {
            if (! $col->isSortable()) continue;
            foreach (['asc', 'desc'] as $dir) {
                $this->attempt("{$short} urut '{$col->getName()}' {$dir}", function () use ($t, $col, $dir) { $t->sortTable($col->getName(), $dir); $t->instance()->getTableRecords(); });
            }
            $stats['sort']++;
        }
        $this->attempt("{$short} reset urut", fn () => $t->sortTable(null));

        // ---- pencarian dengan nilai nyata
        $searchable = collect($table->getColumns())->filter(fn ($c) => $c->isSearchable() && $c->isGloballySearchable());
        if ($searchable->isEmpty() && ! $table->getColumns()) $this->notes[] = "{$short}: tidak ada kolom";
        if ($searchable->isEmpty()) $this->notes[] = "{$short}: tidak ada kolom yang bisa dicari";
        foreach ($searchable as $col) {
            $term = null; $rec = null;
            foreach ($records as $r) {
                $raw = data_get($r, $col->getName());
                if (is_scalar($raw) && ! is_bool($raw) && mb_strlen(trim((string) $raw)) >= 3) { $rec = $r; $term = trim((string) $raw); break; }
            }
            if ($term === null) continue;                                                // kolom kosong pada contoh: tidak bisa diuji
            $term = preg_split('/\s+/', $term)[0] ?: $term;
            if (mb_strlen($term) < 3) $term = trim((string) data_get($rec, $col->getName()));
            $variants = ['asli' => $term];
            if (mb_strtoupper($term) !== $term) $variants['BESAR'] = mb_strtoupper($term);
            if (mb_strtolower($term) !== $term) $variants['kecil'] = mb_strtolower($term);
            foreach ($variants as $label => $v) {
                $this->attempt("{$short} cari '{$col->getName()}' ({$label}: {$v})", function () use ($t, $v, $rec, $col, $label, $short, &$stats) {
                    $t->searchTable($v);
                    $found = $t->instance()->getFilteredTableQuery()->whereKey($rec->getKey())->exists();
                    $stats[$label === 'asli' ? 'search' : 'search_case']++;
                    if (! $found) $this->problem("{$short} pencarian '{$v}' pada kolom '{$col->getName()}' ({$label}) tidak menemukan baris #{$rec->getKey()} yang berisi nilai itu");
                });
            }
            $this->attempt("{$short} reset cari", fn () => $t->searchTable(null));
        }
        foreach (["'", '%', '_', '\\', '"', ';--', '\'; DROP TABLE x;--', 'ü', '   ', '%%', '[a-z]', '(', 'a b c d e'] as $weird) {
            if ($searchable->isEmpty()) break;
            $this->attempt("{$short} cari karakter khusus [" . trim($weird) . ']', function () use ($t, $weird, &$stats) { $t->searchTable($weird); $t->instance()->getTableRecords(); $stats['special']++; });
        }
        $this->attempt("{$short} reset cari", fn () => $t->searchTable(null));

        // ---- filter
        foreach ($table->getFilters() as $f) {
            $fname = $f->getName();
            $stats['filters']++;
            $this->attempt("{$short} reset filter", fn () => $t->resetTableFilters());
            if ($f instanceof SelectFilter) {
                $opts = [];
                $this->attempt("{$short} filter '{$fname}' opsi", function () use ($f, &$opts) { $opts = $f->getOptions(); });
                $attr = null;
                try { $attr = $f->getAttribute(); } catch (\Throwable) {}
                $plain = $attr && ! $f->isMultiple() && ! $this->isRelationshipFilter($f) && ! str_contains($attr, '.') && ! $this->hasCustomQuery($f);
                foreach (array_slice(array_keys($opts), 0, 15) as $opt) {
                    $this->attempt("{$short} filter '{$fname}' = {$opt}", function () use ($t, $fname, $opt, $resource, $attr, $plain, $short, &$stats) {
                        $this->attempt("{$short} reset filter", fn () => $t->resetTableFilters());
                        $this->applyFilter($t, $fname, ['value' => $opt]);
                        $n = $t->instance()->getFilteredTableQuery()->count();
                        $stats['filter_options']++;
                        if ($plain) {
                            // pembanding: baris dasar tabel tanpa filter (menyembunyikan data terhapus), lalu dihitung langsung per nilai
                        $this->applyFilter($t, $fname, ['value' => null]);
                        $expected = (clone $t->instance()->getFilteredTableQuery())->where($attr, $opt)->count();
                        $this->applyFilter($t, $fname, ['value' => $opt]);
                            if ($n !== $expected) $this->problem("{$short} filter '{$fname}' = '{$opt}' menampilkan {$n} baris, database punya {$expected}");
                        }
                    });
                }
            } elseif ($f instanceof TrashedFilter) {
                foreach ([true, false] as $v) $this->attempt("{$short} filter sampah", function () use ($t, $fname, $v, &$stats) { $this->applyFilter($t, $fname, ['value' => $v]); $t->instance()->getFilteredTableQuery()->count(); $stats['filter_options']++; });
            } elseif ($f instanceof TernaryFilter) {
                foreach ([true, false] as $v) $this->attempt("{$short} filter '{$fname}' = " . json_encode($v), function () use ($t, $fname, $v, &$stats) { $this->applyFilter($t, $fname, ['value' => $v]); $t->instance()->getFilteredTableQuery()->count(); $stats['filter_options']++; });
            } elseif ($f instanceof Filter) {
                $this->attempt("{$short} filter '{$fname}'", function () use ($t, $fname, &$stats) { $this->applyFilter($t, $fname, ['isActive' => true]); $t->instance()->getFilteredTableQuery()->count(); $stats['filter_options']++; });
            }
            $this->attempt("{$short} reset filter", fn () => $t->resetTableFilters());
        }
    }

    /** Terapkan filter lewat state komponen (helper filterTable milik Filament butuh PHPUnit, tidak ada di image produksi). */
    private function applyFilter($t, string $name, array $state): void
    {
        foreach ($state as $key => $value) $t->set("tableFilters.{$name}.{$key}", $value);
    }

    private function isRelationshipFilter($filter): bool
    {
        try { return (bool) $filter->getRelationshipName(); } catch (\Throwable) { return false; }
    }

    private function hasCustomQuery($filter): bool
    {
        try {
            $r = new \ReflectionProperty($filter, 'modifyQueryUsing');
            return $r->getValue($filter) !== null;
        } catch (\Throwable) {
            return true;      // tidak bisa dipastikan: jangan bandingkan
        }
    }

    private function auditPages(string $resource, string $short, array &$stats): void
    {
        $first = null;
        $this->attempt("{$short} ambil baris pertama", function () use ($resource, &$first) { $first = $resource::getEloquentQuery()->orderBy((new ($resource::getModel()))->getKeyName())->first(); });
        foreach ($resource::getPages() as $key => $page) {
            if ($key === 'index') continue;
            $class = $page->getPage();
            if (in_array($key, ['view', 'edit'], true)) {
                if (! $first) continue;
                $this->attempt("{$short} halaman {$key}", function () use ($class, $first, &$stats) { Livewire::test($class, ['record' => $first->getKey()]); $stats['pages']++; });
            } elseif ($key === 'create') {
                $this->attempt("{$short} halaman create", function () use ($class, &$stats) { Livewire::test($class); $stats['pages']++; });
            }
        }
    }

    private function auditGlobalSearch(string $resource, string $short): void
    {
        try {
            if (! $resource::canGloballySearch()) return;
            $attr = $resource::getRecordTitleAttribute();
            if (! $attr) return;
            $rec = $resource::getEloquentQuery()->whereNotNull($attr)->first();
            $term = $rec ? trim((string) data_get($rec, $attr)) : null;
            if (! $term || mb_strlen($term) < 3) return;
            $results = $resource::getGlobalSearchResults(preg_split('/\s+/', $term)[0]);
            if ($results->isEmpty()) $this->problem("{$short} pencarian global untuk '{$term}' tidak menemukan apa pun");
        } catch (\Throwable $e) {
            $this->problem("{$short} pencarian global: " . substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200));
        }
    }
}
