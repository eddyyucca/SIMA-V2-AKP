<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use SoftDeletes, Audited, Concerns\HasUniqueSerial;
    protected $guarded = [];
    protected $casts = ['agent_last_seen' => 'datetime', 'purchase_date' => 'date', 'warranty_until' => 'date', 'documents' => 'array', 'spec_data' => 'array', 'purchase_price' => 'decimal:2',
        'rental_start' => 'date', 'rental_end' => 'date', 'returned_at' => 'date', 'retired_at' => 'date', 'rental_monthly_cost' => 'decimal:2', 'rental_deposit' => 'decimal:2'];

    public const STATUSES = [
        'available' => 'Tersedia', 'deployed' => 'Dipakai', 'repair' => 'Dalam Perbaikan',
        'borrowed' => 'Dipinjam', 'lost' => 'Hilang', 'disposed' => 'Disposed', 'returned' => 'Dikembalikan ke Vendor',
    ];
    /** Format spesifikasi laptop & PC desktop (urutan tampil). Kunci = isian, nilai = label & petunjuk. */
    public const SPEC_FIELDS = [
        'name' => ['Name', 'Nama komputer / hostname'],
        'cpu' => ['CPU', 'mis. Intel Core i5-1235U'],
        'ram' => ['RAM', 'mis. 16 GB DDR4'],
        'hdd' => ['HDD', 'mis. SSD 512 GB NVMe'],
        'vga' => ['VGA', 'mis. Intel Iris Xe / NVIDIA MX550'],
        'motherboard' => ['Motherboard', 'mis. Lenovo 21DM'],
        'os' => ['OS', 'mis. Windows 11 Pro 23H2'],
    ];
    /** Pilihan awal untuk kolom spesifikasi berjenis "Pilihan" (bisa diubah di Pengaturan, dan nilai manual bisa ditambahkan dari form aset). */
    public const SPEC_OPTIONS = [
        'cpu' => [
            'Intel Celeron N4020', 'Intel Core i3-10100', 'Intel Core i3-12100', 'Intel Core i3-1115G4', 'Intel Core i5-10400', 'Intel Core i5-12400', 'Intel Core i5-1135G7',
            'Intel Core i5-1235U', 'Intel Core i5-1335U', 'Intel Core i7-1165G7', 'Intel Core i7-1255U', 'Intel Core i7-1355U', 'Intel Core i7-12700', 'Intel Core i9-12900H',
            'AMD Ryzen 3 5300U', 'AMD Ryzen 5 5500U', 'AMD Ryzen 5 5600H', 'AMD Ryzen 7 5700U', 'AMD Ryzen 7 5800H', 'Apple M1', 'Apple M2',
        ],
        'ram' => ['4 GB DDR4', '8 GB DDR4', '16 GB DDR4', '32 GB DDR4', '8 GB DDR5', '16 GB DDR5', '32 GB DDR5', '64 GB DDR5'],
        'hdd' => [
            'HDD 500 GB', 'HDD 1 TB', 'HDD 2 TB', 'SSD 128 GB', 'SSD 256 GB', 'SSD 512 GB', 'SSD 1 TB', 'SSD NVMe 256 GB', 'SSD NVMe 512 GB', 'SSD NVMe 1 TB', 'SSD NVMe 2 TB',
        ],
        'vga' => [
            'Onboard / Integrated', 'Intel UHD Graphics', 'Intel Iris Xe Graphics', 'AMD Radeon Graphics', 'NVIDIA GeForce MX450', 'NVIDIA GeForce MX550',
            'NVIDIA GeForce GTX 1650', 'NVIDIA GeForce RTX 3050', 'NVIDIA GeForce RTX 3060', 'NVIDIA GeForce RTX 4060',
        ],
    ];
    public const CONDITIONS = ['good' => 'Baik', 'minor_damage' => 'Rusak Ringan', 'major_damage' => 'Rusak Berat'];

    protected static function booted(): void
    {
        // kode aset diganti (mis. diubah di form): kode lama disimpan supaya label lama tetap bisa discan
        static::updated(function (Asset $a) {
            if ($a->wasChanged('asset_tag') && filled($old = $a->getOriginal('asset_tag'))) {
                AssetTagAlias::firstOrCreate(['asset_id' => $a->id, 'tag' => $old]);
            }
        });
        static::saving(function (Asset $a) {
            if ($a->isDirty('spec_data')) $a->spec_data = static::cleanSpecData($a->spec_data);
            // penyusutan berhenti saat aset dilepas (disposal) atau hilang
            if (in_array($a->status, ['disposed', 'lost'], true) && ! $a->retired_at) {
                $a->retired_at = now()->toDateString();
            }
            // 1) pilihan master -> kolom teks (dipakai label, laporan, pencarian)
            if ($a->brand_id && ($a->isDirty('brand_id') || blank($a->brand))) {
                $a->brand = Brand::withTrashed()->find($a->brand_id)?->name;
            }
            if ($a->asset_model_id) {
                $m = AssetModel::withTrashed()->find($a->asset_model_id);
                if ($m && $a->brand_id && (int) $m->brand_id !== (int) $a->brand_id) {   // model milik merek lain -> lepas
                    $a->asset_model_id = null;
                    $a->model = null;
                    $m = null;
                }
                if ($m && ! $a->brand_id) {
                    $a->brand_id = $m->brand_id;
                    $a->brand = $m->brand?->name;
                }
                if ($m && ($a->isDirty('asset_model_id') || blank($a->model))) $a->model = $m->name;
            }
            // 2) teks -> master (impor, seeder, atau kode lain yang hanya mengisi teks)
            if (! $a->brand_id && filled($a->brand)) {
                $b = Brand::resolve($a->brand);
                $a->brand_id = $b->id;
                $a->brand = $b->name;
            }
            if (! $a->asset_model_id && filled($a->model) && $a->brand_id) {
                $m = AssetModel::resolve((int) $a->brand_id, $a->model, $a->category_id);
                $a->asset_model_id = $m->id;
                $a->model = $m->name;
            }
        });
    }

    public function brandRef() { return $this->belongsTo(Brand::class, 'brand_id')->withTrashed(); }
    /** Foto aset; bila belum ada, foto modelnya (disk public). */
    public function displayPhoto(): ?string { return $this->photo_path ?: $this->modelRef?->photo_path; }
    public function modelRef() { return $this->belongsTo(AssetModel::class, 'asset_model_id')->withTrashed(); }

    /** Status akhir: tidak lagi dihitung sebagai aset aktif. */
    public const INACTIVE = ['disposed', 'returned'];
    public const OWNERSHIP = ['owned' => 'Milik Sendiri', 'rental' => 'Sewa (Rental)'];

    /** Kategori laptop memakai format spesifikasi terstruktur (nama "Laptop"/"Notebook" atau awalan kode NB / LPT). */
    public static function isLaptopCategory(int|string|null $categoryId): bool
    {
        static $cache = [];
        if (! $categoryId) return false;
        return $cache[$categoryId] ??= (function () use ($categoryId) {
            $c = Category::find($categoryId);
            return $c && (in_array(mb_strtolower(trim($c->name)), ['laptop', 'notebook'], true) || in_array(strtoupper((string) $c->code_prefix), ['NB', 'LPT'], true));
        })();
    }

    /** Laptop ATAU PC desktop: keduanya memakai format spesifikasi terstruktur yang sama. */
    public static function isComputerCategory(int|string|null $categoryId): bool
    {
        static $cache = [];
        if (! $categoryId) return false;
        return $cache[$categoryId] ??= (function () use ($categoryId) {
            $c = Category::find($categoryId);
            if (! $c) return false;
            $name = mb_strtolower(trim($c->name));
            return in_array($name, ['laptop', 'notebook', 'pc', 'pc desktop', 'desktop', 'desktop pc', 'komputer desktop'], true)
                || in_array(strtoupper((string) $c->code_prefix), ['NB', 'LPT', 'PC'], true);
        })();
    }

    /**
     * Kolom spesifikasi yang berlaku (dari Pengaturan; bawaan SPEC_FIELDS).
     * Kunci => [label, petunjuk, jenis ('text' | 'select'), daftar pilihan].
     * Pengaturan lama tanpa jenis/pilihan memakai bawaan kolom yang sama (CPU/RAM/HDD/VGA = Pilihan).
     */
    public static function specFields(): array
    {
        $raw = Setting::get('spec_fields');
        $list = $raw ? json_decode($raw, true) : null;
        $out = [];
        if (is_array($list)) {
            foreach ($list as $f) {
                if (! is_array($f) || ! isset($f['key'], $f['label']) || $f['key'] === '') continue;
                $k = (string) $f['key'];
                $type = in_array($f['type'] ?? null, ['text', 'select'], true) ? $f['type'] : (isset(self::SPEC_OPTIONS[$k]) ? 'select' : 'text');
                $opts = isset($f['options']) && is_array($f['options']) ? $f['options'] : (self::SPEC_OPTIONS[$k] ?? []);
                $out[$k] = [(string) $f['label'], (string) ($f['hint'] ?? ''), $type, self::cleanOptions($opts)];
            }
        }
        if ($out) return $out;
        foreach (self::SPEC_FIELDS as $k => $f) $out[$k] = [$f[0], $f[1], isset(self::SPEC_OPTIONS[$k]) ? 'select' : 'text', self::SPEC_OPTIONS[$k] ?? []];
        return $out;
    }

    /** Daftar pilihan dirapikan: dipangkas, tanpa kosong / ganda (huruf besar-kecil dianggap sama), maks. 200 pilihan @ 100 karakter. */
    public static function cleanOptions(array $options): array
    {
        $seen = []; $out = [];
        foreach ($options as $o) {
            $o = mb_substr(trim((string) $o), 0, 100);
            if ($o === '' || isset($seen[mb_strtolower($o)])) continue;
            $seen[mb_strtolower($o)] = true;
            $out[] = $o;
            if (count($out) >= 200) break;
        }
        return $out;
    }

    /** Tambahkan satu nilai ke daftar pilihan sebuah kolom (mis. nilai manual dari form aset). True bila ditambahkan. */
    public static function addSpecOption(string $key, string $value): bool
    {
        $fields = self::specFields();
        $value = mb_substr(trim($value), 0, 100);
        if ($value === '' || ! isset($fields[$key]) || $fields[$key][2] !== 'select') return false;
        $before = $fields[$key][3];
        $fields[$key][3] = self::cleanOptions([...$before, $value]);
        if ($fields[$key][3] === $before) return false;
        $store = [];
        foreach ($fields as $k => [$label, $hint, $type, $opts]) $store[] = ['key' => $k, 'label' => $label, 'hint' => $hint, 'type' => $type, 'options' => $opts];
        Setting::put('spec_fields', json_encode($store, JSON_UNESCAPED_UNICODE));
        return true;
    }

    /** Hanya kunci yang dikenal, nilai dirapikan; kosong semua -> null. */
    public static function cleanSpecData(?array $data): ?array
    {
        $out = [];
        foreach (array_keys(self::specFields()) as $k) {
            $v = trim((string) ($data[$k] ?? ''));
            if ($v !== '') $out[$k] = mb_substr($v, 0, 255);
        }
        return $out ?: null;
    }

    public function scopeActive($q) { return $q->whereNotIn('status', self::INACTIVE); }
    public function isRental(): bool { return $this->ownership === 'rental'; }

    /** Tahun aset (tahun beli; aset sewa = tahun mulai sewa), untuk teks label. */
    public function labelYear(): ?string { return $this->purchase_date?->format('Y'); }

    /** Teks serial di label & BAST: "2021 | SND958E76EE8" (tanpa tahun bila tanggal beli kosong). */
    public function labelSerialText(): string
    {
        $sn = filled($this->serial_number) ? (string) $this->serial_number : '-';
        return ($y = $this->labelYear()) ? "{$y} | {$sn}" : $sn;
    }

    /** Sisa hari sampai sewa berakhir (negatif = lewat jatuh tempo). */
    public function getRentalDaysLeftAttribute(): ?int
    {
        return $this->rental_end ? (int) now()->startOfDay()->diffInDays($this->rental_end, false) : null;
    }

    /** Pencadangan aktif untuk rencana kedatangan karyawan (satu aset hanya bisa dicadangkan sekali). */
    public function reservationItem() { return $this->hasOne(ArrivalPlanItem::class)->whereIn('status', ['planned', 'prepared', 'ready']); }

    // ---- SIMA Asset Agent (dipindah dari SIMA): token, laporan terkini, perintah, chat, riwayat spesifikasi ----
    public function agentTokens() { return $this->hasMany(AssetAgentToken::class); }
    /** Laporan TERKINI (satu baris per aset, bukan log). */
    public function agentHeartbeat() { return $this->hasOne(AssetHeartbeat::class); }
    public function chatSessions() { return $this->hasMany(AgentChatSession::class)->orderByDesc('id'); }
    public function agentCommands() { return $this->hasMany(AssetAgentCommand::class)->orderByDesc('id'); }
    public function specChanges() { return $this->hasMany(AssetSpecChange::class)->orderByDesc('changed_at')->orderByDesc('id'); }

    public function category() { return $this->belongsTo(Category::class); }
    public function vendor() { return $this->belongsTo(Vendor::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function bastFiles() { return $this->hasMany(AssetBastFile::class); }
    public function rentalContracts() { return $this->hasMany(RentalContract::class)->orderByDesc('start_date')->orderByDesc('id'); }

    /** Kontrak berjalan (periode terbaru) per jenis, urut dari yang paling baru berakhir. Kunci = jenis (huruf kecil). */
    public function currentContracts(): \Illuminate\Support\Collection
    {
        return $this->rentalContracts->groupBy(fn ($c) => $c->groupKey())
            ->map(fn ($rows) => $rows->sortByDesc(fn ($c) => $c->end_date->timestamp . str_pad((string) $c->id, 10, '0', STR_PAD_LEFT))->values())
            ->sortByDesc(fn ($rows) => $rows[0]->end_date->timestamp);
    }

    /** Status kontrak sewa aset: Masa Aktif / Expired (berdasarkan tanggal berakhir sewa), atau Dikembalikan. */
    public function getRentalContractStatusAttribute(): ?string
    {
        if (! $this->isRental() || ! $this->rental_end) return null;
        if ($this->status === 'returned') return 'Dikembalikan';
        return $this->rental_days_left >= 0 ? 'Masa Aktif' : 'Expired';
    }
    public function tagAliases() { return $this->hasMany(AssetTagAlias::class); }

    /**
     * Cari aset dari hasil scan / ketikan: kode aset, serial number, atau kode yang pernah dipakai aset itu.
     * Isi QR berbentuk alamat (…/s/KODE atau …?kode=KODE) juga dikenali.
     */
    public static function findByCode(string $scan): ?self
    {
        $code = static::extractCode($scan);
        if ($code === '') return null;
        // persis dulu; lalu tanpa membedakan huruf (PostgreSQL membedakan huruf pada "=", SIMA/MariaDB tidak: scan 'akp-it-nb-0001' harus tetap ketemu)
        $lc = mb_strtolower($code);
        return static::where('asset_tag', $code)->orWhere('serial_number', $code)->first()
            ?? static::whereIn('id', AssetTagAlias::where('tag', $code)->pluck('asset_id'))->first()
            ?? static::whereRaw('lower(asset_tag) = ?', [$lc])->orWhereRaw('lower(serial_number) = ?', [$lc])->first()
            ?? static::whereIn('id', AssetTagAlias::whereRaw('lower(tag) = ?', [$lc])->pluck('asset_id'))->first();
    }

    /** Ambil kode dari teks scan: alamat (path terakhir atau parameter kode) atau teks biasa. */
    public static function extractCode(string $scan): string
    {
        $scan = trim($scan);
        if (preg_match('#^https?://#i', $scan)) {
            $parts = parse_url($scan);
            parse_str($parts['query'] ?? '', $q);
            $scan = isset($q['kode']) && is_string($q['kode']) ? $q['kode'] : rawurldecode(basename($parts['path'] ?? ''));
        }
        return trim($scan);
    }

    public function assignments() { return $this->hasMany(AssetAssignment::class)->latest('assigned_at')->latest('id'); }
    public function repairs() { return $this->hasMany(Repair::class)->latest('reported_at'); }
    public function disposals() { return $this->hasMany(Disposal::class); }
    public function components()
    {
        return $this->hasMany(StockMovement::class)->where('stockable_type', Component::class);
    }

    /** Dokumen pemasangan / pelepasan komponen pada aset ini (terbaru dulu). */
    public function installHandovers() { return $this->hasMany(AccessoryHandover::class, 'asset_id')->where('type', 'install')->latest('handed_at')->latest('id'); }

    /** Komponen terpasang = install - uninstall, per komponen (pada $asOf bila diberikan, bila tidak: saat ini). */
    public function installedComponents(?\Illuminate\Support\Carbon $asOf = null)
    {
        return StockMovement::where('asset_id', $this->id)
            ->where('stockable_type', Component::class)
            ->whereIn('type', ['install', 'uninstall'])
            ->when($asOf, fn ($q) => $q->where('moved_at', '<=', $asOf))        // keadaan pada tanggal tertentu (mis. saat BAST dibuat)
            ->get()->groupBy('stockable_id')
            ->map(fn ($rows, $id) => [
                'component' => Component::withTrashed()->find($id),
                'qty' => (float) $rows->sum(fn ($r) => $r->type === 'install' ? $r->qty : -$r->qty),
            ])->filter(fn ($r) => $r['qty'] > 0 && $r['component'] !== null)->values();   // komponen yang datanya sudah dihapus permanen dilewati (tidak membuat halaman error)
    }

    /**
     * Komponen terpasang beserta data yang terhubung: tanggal pasang terakhir, dokumen pemasangan (bila ada), serta PO / harga dari pembelian.
     * @return \Illuminate\Support\Collection<int,array{component:Component,qty:float,installed_at:?\Illuminate\Support\Carbon,doc:?AccessoryHandover,po:?string,cost:float}>
     */
    public function installedComponentsDetailed(?\Illuminate\Support\Carbon $asOf = null)
    {
        return $this->installedComponents($asOf)->map(function ($r) use ($asOf) {
            $c = $r['component'];
            $last = StockMovement::where('asset_id', $this->id)->where('stockable_type', Component::class)->where('stockable_id', $c->id)->where('type', 'install')
                ->when($asOf, fn ($q) => $q->where('moved_at', '<=', $asOf))->orderByDesc('moved_at')->orderByDesc('id')->first();
            $doc = $last?->handoverItem?->handover;
            $receipt = $c->latestReceipt();
            return ['component' => $c, 'qty' => $r['qty'], 'installed_at' => $last?->moved_at, 'doc' => ($doc && $doc->type === 'install') ? $doc : null,
                'po' => $receipt?->reference_no ?: null, 'cost' => (float) ($receipt?->unit_price ?? $c->price ?? 0)];
        });
    }

    /** Awalan kode aset (menu Pengaturan; bawaan AKP-IT). */
    public static function tagPrefix(): string
    {
        return strtoupper(trim((string) Setting::get('asset_tag_prefix', config('asset_tag.prefix')))) ?: 'AKP-IT';
    }

    /** Jumlah digit nomor urut pada kode aset (bawaan 4: AKP-IT-NB-0001). Bisa diubah lewat ASSET_TAG_DIGITS atau pengaturan asset_tag_digits (3–8). */
    public static function tagDigits(): int
    {
        return max(3, min(8, (int) (Setting::get('asset_tag_digits') ?: config('asset_tag.digits', 4))));
    }

    /** Kode berikutnya: {awalan}-{kategori}-{urut}; urut berjalan per kategori (termasuk aset yang sudah dihapus), tanpa tahun. */
    public static function nextTag(string $prefix, string $categoryCode): string
    {
        $head = strtoupper($prefix) . '-' . strtoupper($categoryCode ?: 'AST') . '-';
        $max = 0;
        foreach (static::withTrashed()->where('asset_tag', 'like', $head . '%')->pluck('asset_tag') as $t) {
            if (preg_match('/-(\d+)$/', $t, $m)) $max = max($max, (int) $m[1]);
        }
        return $head . str_pad((string) ($max + 1), static::tagDigits(), '0', STR_PAD_LEFT);
    }

    public static function generateTag(Category $category): string
    {
        return static::nextTag(static::tagPrefix(), (string) $category->code_prefix);
    }
}
