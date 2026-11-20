<?php

namespace App\Filament\Pages;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\Vendor;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;
use UnitEnum;

class ImportAssets extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpOnSquare;
    protected static string|UnitEnum|null $navigationGroup = 'Inventaris';
    protected static ?int $navigationSort = 9;
    protected static ?string $navigationLabel = 'Import Aset';
    protected static ?string $title = 'Import Aset dari Excel / CSV';
    protected static ?string $slug = 'import-aset';
    protected string $view = 'filament.pages.import-assets';

    public $file = null;
    public array $errors_list = [];
    public ?int $imported = null;

    public static function canAccess(): bool { return auth()->user()?->can('manage inventory') ?? false; }

    /** Judul kolom → kunci spesifikasi: kunci (cpu, ram, …) atau label kolom diubah ke huruf kecil bergaris bawah ("Kapasitas HDD" → kapasitas_hdd). */
    public static function specColumns(): array
    {
        $out = [];
        foreach (Asset::specFields() as $key => $f) {
            $out[(string) $key] = (string) $key;
            $out[\Illuminate\Support\Str::slug($f[0], '_')] = (string) $key;
        }
        return $out;
    }

    /** Nilai spesifikasi dari satu baris impor. Pada kolom berjenis Pilihan, nilai baru ikut masuk ke daftar pilihan. */
    public static function specFromRow(array $row): ?array
    {
        $data = [];
        foreach (static::specColumns() as $col => $key) {
            $v = trim((string) ($row[$col] ?? ''));
            if ($v !== '' && ! isset($data[$key])) $data[$key] = $v;
        }
        $data = Asset::cleanSpecData($data);
        foreach ($data ?? [] as $key => $v) Asset::addSpecOption($key, $v);
        return $data;
    }

    public function import(): void
    {
        $this->validate(['file' => ['required', 'file', 'max:5120', \App\Support\SpreadsheetFile::extensionRule()]]);
        $reader = new \App\Reports\RowsImport;
        try {
            Excel::import($reader, $this->file->getRealPath(), null, \App\Support\SpreadsheetFile::readerType($this->file->getRealPath()));
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->danger()->title('File tidak bisa dibaca')->body('Pastikan file Excel/CSV dengan baris pertama berisi judul kolom. ' . mb_substr($e->getMessage(), 0, 140))->send();
            return;
        }
        $rows = $reader->rows;
        $errors = []; $ok = 0; $seen = [];

        DB::transaction(function () use ($rows, &$errors, &$ok, &$seen) {
            foreach ($rows as $i => $r) {
                $n = $i + 2; // baris di file (1 = header)
                $cat = Category::where('type', 'asset')->whereCi('name', trim((string) ($r['kategori'] ?? '')))->first();
                if (! $cat) { $errors[] = "Baris {$n}: kategori '" . ($r['kategori'] ?? '') . "' tidak dikenal."; continue; }
                $sn = \App\Support\SerialNumber::clean($r['serial_number'] ?? null);
                if ($sn && isset($seen[mb_strtolower($sn)])) {
                    $errors[] = "Baris {$n}: serial number '{$sn}' duplikat (sama dengan baris {$seen[mb_strtolower($sn)]} di file ini)."; continue;
                }
                if ($sn && ($h = \App\Support\SerialNumber::holder($sn))) {
                    $errors[] = "Baris {$n}: serial number '{$sn}' duplikat (sudah dipakai oleh {$h})."; continue;
                }
                if ($sn) $seen[mb_strtolower($sn)] = $n;
                $parse = fn ($v) => $v ? (is_numeric($v) ? \Carbon\Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v)) : \Carbon\Carbon::parse($v)) : null;
                $own = in_array(strtolower(trim((string) ($r['kepemilikan'] ?? ''))), ['sewa', 'rental'], true) ? 'rental' : 'owned';
                if ($own === 'rental' && (! $parse($r['sewa_mulai'] ?? null) || ! $parse($r['sewa_berakhir'] ?? null))) {
                    $errors[] = "Baris {$n}: aset sewa wajib mengisi sewa_mulai dan sewa_berakhir.";
                    continue;
                }
                $spec = static::specFromRow($r);
                try {
                    Asset::create([
                        'spec_data' => $spec,
                        'asset_tag' => Asset::generateTag($cat), 'category_id' => $cat->id,
                        'brand' => $r['merek'] ?? null, 'model' => $r['model'] ?? null, 'serial_number' => $sn,
                        'purchase_date' => $parse($r['tanggal_beli'] ?? null), 'purchase_price' => (float) ($r['harga'] ?? 0),
                        'warranty_until' => $parse($r['garansi_sampai'] ?? null),
                        'location_id' => Location::whereCi('name', trim((string) ($r['lokasi'] ?? '')))->value('id'),
                        'vendor_id' => Vendor::whereCi('name', trim((string) ($r['vendor'] ?? '')))->value('id'),
                        'status' => 'available', 'condition' => 'good',
                        'ownership' => $own,
                        'rental_contract_no' => $own === 'rental' ? ($r['no_kontrak'] ?? null) : null,
                        'rental_start' => $own === 'rental' ? $parse($r['sewa_mulai'] ?? null) : null,
                        'rental_end' => $own === 'rental' ? $parse($r['sewa_berakhir'] ?? null) : null,
                        'rental_monthly_cost' => $own === 'rental' ? (float) ($r['biaya_sewa_bulanan'] ?? 0) : 0,
                    ]);
                    $ok++;
                } catch (\Throwable $e) {
                    $errors[] = "Baris {$n}: " . $e->getMessage();
                }
            }
        });

        $this->errors_list = $errors;
        $this->imported = $ok;
        $this->file = null;
        Notification::make()->title("{$ok} aset diimpor, " . count($errors) . ' baris ditolak')->color($errors ? 'warning' : 'success')->send();
    }
}
