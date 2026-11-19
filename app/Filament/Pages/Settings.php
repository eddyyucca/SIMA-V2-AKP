<?php

namespace App\Filament\Pages;

use App\Models\Asset;
use App\Models\Setting;
use App\Support\BastDocument;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use UnitEnum;

class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;
    protected static string|UnitEnum|null $navigationGroup = 'Master Data';
    protected static ?int $navigationSort = 9;
    protected static ?string $navigationLabel = 'Pengaturan';
    protected static ?string $title = 'Pengaturan';
    protected static ?string $slug = 'pengaturan';
    protected string $view = 'filament.pages.settings';

    public string $prefix = '';

    /** @var array<string,string> awalan kode Accessories / Consumable / Komponen */
    public array $codes = [];

    /** @var array<string,string> pengaturan dokumen BAST (kop, revisi, nama penandatangan) */
    public array $bast = [];

    /** @var list<array{key:string,label:string,hint:string}> kolom spesifikasi laptop & PC */
    public array $specFields = [];

    /** Umur ekonomis bawaan (tahun) untuk kategori aset tanpa nilai sendiri. */
    public string $lifeDefault = '';

    /** @var list<array{id:int,name:string,years:string,residual:string}> umur ekonomis & residu per kategori aset */
    public array $lifeRows = [];

    public static function canAccess(): bool { return auth()->user()?->can('manage master') ?? false; }

    public function mount(): void
    {
        $this->prefix = Asset::tagPrefix();
        foreach (\App\Support\CodePrefix::DEFAULTS as $k => $_) $this->codes[$k] = \App\Support\CodePrefix::get($k);
        foreach (BastDocument::SETTINGS as $k => $_) $this->bast[$k] = BastDocument::setting($k);
        $this->loadSpecFields();
        $this->loadLife();
    }

    private function loadLife(): void
    {
        $this->lifeDefault = (string) \App\Models\Category::defaultLifeYears();
        $this->lifeRows = \App\Models\Category::where('type', 'asset')->orderBy('name')->get()->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'years' => (string) ($c->useful_life_years ?? ''), 'residual' => rtrim(rtrim(number_format((float) $c->residual_percent, 2, '.', ''), '0'), '.') ?: '0',
        ])->all();
    }

    public function saveLife(): void
    {
        $this->validate([
            'lifeDefault' => ['required', 'integer', 'min:1', 'max:50'],
            'lifeRows.*.years' => ['nullable', 'integer', 'min:1', 'max:50'],
            'lifeRows.*.residual' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'lifeDefault.*' => 'Umur bawaan harus bilangan bulat 1–50 tahun.',
            'lifeRows.*.years.*' => 'Umur ekonomis harus bilangan bulat 1–50 tahun (kosong = ikut umur bawaan).',
            'lifeRows.*.residual.*' => 'Nilai residu harus 0–100%.',
        ]);
        foreach ($this->lifeRows as $r) {
            \App\Models\Category::where('type', 'asset')->whereKey($r['id'])->update([
                'useful_life_years' => ($r['years'] ?? '') === '' ? null : (int) $r['years'],
                'residual_percent' => ($r['residual'] ?? '') === '' ? 0 : (float) $r['residual'],
            ]);
        }
        Setting::put('depreciation_default_years', (string) (int) $this->lifeDefault);
        $this->loadLife();
        Notification::make()->title('Umur ekonomis disimpan')->body('Terisi otomatis saat menambah aset baru di kategori itu, dan dipakai menghitung penyusutan. Aset yang sudah punya umur sendiri tidak berubah.')->success()->send();
        $this->closeSection('saveLife');
    }

    /**
     * Daftar kartu pengaturan. Menambah pengaturan baru: tambah satu entri di sini + satu berkas di
     * resources/views/filament/pages/settings/ (isi pop-up) + method simpan yang memanggil closeSection().
     * @return list<array{id:string,title:string,desc:string,icon:string,summary:string,view:string,save:string,reset?:string}>
     */
    public function sections(): array
    {
        $cols = collect(Asset::specFields())->map(fn ($f) => $f[0])->values();
        $nSelect = collect(Asset::specFields())->where(2, 'select')->count();
        return [
            ['id' => 'kode', 'title' => 'Kode aset', 'icon' => 'heroicon-o-qr-code', 'view' => 'kode-aset', 'save' => 'save',
                'desc' => 'Awalan kode untuk aset baru (laptop, PC, monitor, dan lainnya).',
                'summary' => 'Contoh: ' . Asset::tagPrefix() . '-NB-' . str_pad('1', Asset::tagDigits(), '0', STR_PAD_LEFT)],
            ['id' => 'penomoran', 'title' => 'Penomoran Accessories, Consumable & Komponen', 'icon' => 'heroicon-o-hashtag', 'view' => 'penomoran', 'save' => 'saveCodes',
                'desc' => 'Awalan kode untuk label dan scan QR barang pendukung.',
                'summary' => collect(\App\Support\CodePrefix::DEFAULTS)->map(fn ($_, $k) => \App\Support\CodePrefix::get($k))->implode(' · ')],
            ['id' => 'umur', 'title' => 'Umur ekonomis per kategori aset', 'icon' => 'heroicon-o-calendar-days', 'view' => 'umur-ekonomis', 'save' => 'saveLife',
                'desc' => 'Umur ekonomis (penyusutan) tiap kategori; terisi otomatis saat menambah aset, jadi tidak perlu diisi lagi.',
                'summary' => \App\Models\Category::where('type', 'asset')->orderBy('name')->take(4)->get()->map(fn ($c) => $c->name . ' ' . $c->effectiveLifeYears() . ' th')->implode(' · ') . ' …'],
            ['id' => 'bast', 'title' => 'Dokumen BAST', 'icon' => 'heroicon-o-document-text', 'view' => 'bast', 'save' => 'saveBast',
                'desc' => 'Kop perusahaan, status dokumen, dan nama penandatangan pada PDF BAST.',
                'summary' => BastDocument::setting('bast_company') . ' · ' . BastDocument::setting('bast_revision')],
            ['id' => 'spesifikasi', 'title' => 'Kolom spesifikasi Laptop & PC Desktop', 'icon' => 'heroicon-o-cpu-chip', 'view' => 'spesifikasi', 'save' => 'saveSpecFields', 'reset' => 'resetSpecFields',
                'desc' => 'Isian spesifikasi pada form aset Laptop dan PC Desktop: ubah, tambah, hapus, urutkan.',
                'summary' => $cols->count() . ' kolom (' . $nSelect . ' berupa pilihan): ' . $cols->take(4)->implode(', ') . ($cols->count() > 4 ? ', …' : '')],
        ];
    }

    private const SECTION_MODALS = ['save' => 'set-kode', 'saveCodes' => 'set-penomoran', 'saveBast' => 'set-bast', 'saveSpecFields' => 'set-spesifikasi', 'saveLife' => 'set-umur'];

    /** Tutup pop-up bagian yang baru disimpan. */
    private function closeSection(string $method): void
    {
        $this->dispatch('close-modal', id: self::SECTION_MODALS[$method]);
    }

    /** Pop-up ditutup tanpa menyimpan: kembalikan isian ke nilai tersimpan. */
    public function reloadSettings(): void
    {
        $this->resetErrorBag();
        $this->mount();
    }

    private function loadSpecFields(): void
    {
        $this->specFields = collect(Asset::specFields())->map(fn ($f, $k) => [
            'key' => (string) $k, 'label' => $f[0], 'hint' => $f[1], 'type' => $f[2], 'options' => implode("\n", $f[3]),   // pilihan: satu per baris
        ])->values()->all();
    }

    public function addSpecField(): void
    {
        if (count($this->specFields) >= 20) return;
        $this->specFields[] = ['key' => '', 'label' => '', 'hint' => '', 'type' => 'text', 'options' => ''];
    }

    public function removeSpecField(int $i): void
    {
        unset($this->specFields[$i]);
        $this->specFields = array_values($this->specFields);
    }

    public function moveSpecField(int $i, int $dir): void
    {
        $j = $i + $dir;
        if (! isset($this->specFields[$i], $this->specFields[$j])) return;
        [$this->specFields[$i], $this->specFields[$j]] = [$this->specFields[$j], $this->specFields[$i]];
    }

    public function resetSpecFields(): void
    {
        Setting::put('spec_fields', null);
        $this->loadSpecFields();
        Notification::make()->title('Kolom spesifikasi dikembalikan ke bawaan')->success()->send();
    }

    public function saveSpecFields(): void
    {
        foreach ($this->specFields as $i => $f) {
            $this->specFields[$i]['label'] = trim((string) ($f['label'] ?? ''));
            $this->specFields[$i]['hint'] = trim((string) ($f['hint'] ?? ''));
            $this->specFields[$i]['type'] = ($f['type'] ?? 'text') === 'select' ? 'select' : 'text';
            $this->specFields[$i]['options'] = is_array($f['options'] ?? null) ? implode("\n", $f['options']) : (string) ($f['options'] ?? '');
        }
        $this->validate([
            'specFields' => ['required', 'array', 'min:1', 'max:20'],
            'specFields.*.label' => ['required', 'max:30', 'distinct:ignore_case'],
            'specFields.*.hint' => ['nullable', 'max:80'],
            'specFields.*.type' => ['required', 'in:text,select'],
            'specFields.*.options' => ['nullable', 'string', 'max:20000', function ($attribute, $value, $fail) {
                $lines = array_filter(array_map('trim', preg_split('/\R/', (string) $value)), fn ($l) => $l !== '');
                if (count($lines) > 200) $fail('Maksimal 200 pilihan per kolom.');
                foreach ($lines as $l) if (mb_strlen($l) > 100) { $fail('Tiap pilihan maksimal 100 karakter: "' . mb_strimwidth($l, 0, 30, '…') . '"'); break; }
            }],
        ], [
            'specFields.required' => 'Minimal satu kolom spesifikasi.', 'specFields.min' => 'Minimal satu kolom spesifikasi.', 'specFields.max' => 'Maksimal 20 kolom.',
            'specFields.*.label.required' => 'Nama kolom wajib diisi.', 'specFields.*.label.max' => 'Nama kolom maksimal 30 karakter.',
            'specFields.*.label.distinct' => 'Nama kolom tidak boleh sama.', 'specFields.*.hint.max' => 'Petunjuk maksimal 80 karakter.',
        ]);
        // kunci lama dipertahankan (data aset tetap terbaca walau label diganti); kolom baru diberi kunci dari namanya
        $used = collect($this->specFields)->pluck('key')->filter()->all();
        foreach ($this->specFields as $i => $f) {
            if ($f['key'] !== '') continue;
            $base = Str::slug($f['label'], '_') ?: 'kolom';
            $key = $base; $n = 2;
            while (in_array($key, $used, true)) $key = $base . '_' . $n++;
            $used[] = $key;
            $this->specFields[$i]['key'] = $key;
        }
        $store = array_map(fn ($f) => [
            'key' => $f['key'], 'label' => $f['label'], 'hint' => $f['hint'], 'type' => $f['type'],
            'options' => Asset::cleanOptions(preg_split('/\R/', (string) $f['options'])),
        ], $this->specFields);
        Setting::put('spec_fields', json_encode($store, JSON_UNESCAPED_UNICODE));
        $this->loadSpecFields();
        Notification::make()->title('Kolom spesifikasi disimpan')->body('Berlaku di form Laptop dan PC Desktop. Isian aset lama tetap tersimpan; kolom yang dihapus tidak ditampilkan lagi.')->success()->send();
        $this->closeSection('saveSpecFields');
    }

    /** Contoh kode berikutnya dengan awalan yang sedang diketik. */
    public function example(): string
    {
        $p = strtoupper(trim($this->prefix));
        return preg_match('/^[A-Z0-9]+(-[A-Z0-9]+)*$/', $p) ? Asset::nextTag($p, 'NB') : '—';
    }

    public function codeExample(string $kind): string
    {
        $p = strtoupper(trim($this->codes[$kind] ?? ''));
        return preg_match('/^[A-Z0-9]+(-[A-Z0-9]+)*$/', $p) ? $p . '-0001' : '—';
    }

    public function saveCodes(): void
    {
        foreach ($this->codes as $k => $v) $this->codes[$k] = strtoupper(trim((string) $v));
        $this->validate([
            'codes.*' => ['required', 'max:10', 'regex:/^[A-Z0-9]+(-[A-Z0-9]+)*$/'],
        ], ['codes.*.required' => 'Awalan wajib diisi.', 'codes.*.max' => 'Awalan maksimal 10 karakter.', 'codes.*.regex' => 'Hanya huruf, angka, dan tanda hubung.']);
        if (count(array_unique($this->codes)) < count($this->codes)) {
            $this->addError('codes.accessory', 'Awalan ketiga jenis barang tidak boleh sama.');
            return;
        }
        foreach ($this->codes as $k => $v) Setting::put('code_prefix_' . $k, $v);
        Notification::make()->title('Penomoran disimpan')->body('Berlaku untuk label dan scan berikutnya. Label lama dengan awalan bawaan (ACC/CSM/CMP) tetap bisa di-scan.')->success()->send();
        $this->closeSection('saveCodes');
    }

    public function saveBast(): void
    {
        $rules = [];
        foreach (BastDocument::SETTINGS as $k => [$default, $label]) {
            $this->bast[$k] = trim((string) ($this->bast[$k] ?? $default));
            $rules["bast.$k"] = [$k === 'bast_company' ? 'required' : 'nullable', 'max:100'];
        }
        $this->validate($rules, ['bast.bast_company.required' => 'Nama perusahaan wajib diisi.', 'max' => 'Maksimal 100 karakter.']);
        foreach ($this->bast as $k => $v) Setting::put($k, $v);
        Notification::make()->title('Pengaturan BAST disimpan')->body('Berlaku untuk BAST yang dibuat atau dicetak berikutnya.')->success()->send();
        $this->closeSection('saveBast');
    }

    public function save(): void
    {
        $this->prefix = strtoupper(trim($this->prefix));
        $this->validate(
            ['prefix' => ['required', 'max:20', 'regex:/^[A-Z0-9]+(-[A-Z0-9]+)*$/']],
            ['prefix.required' => 'Awalan wajib diisi.', 'prefix.max' => 'Awalan maksimal 20 karakter.', 'prefix.regex' => 'Hanya huruf, angka, dan tanda hubung (contoh: AKP-IT).']
        );
        Setting::put('asset_tag_prefix', $this->prefix);
        Notification::make()->title('Pengaturan disimpan')->body('Awalan baru berlaku untuk kode aset berikutnya. Kode yang sudah ada tidak berubah.')->success()->send();
        $this->closeSection('save');
    }
}
