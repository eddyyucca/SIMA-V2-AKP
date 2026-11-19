<?php

namespace Database\Seeders;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Department;
use App\Models\Employee;
use App\Models\IpAddress;
use App\Models\ITRequest;
use App\Models\License;
use App\Models\Location;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AccessoryHandoverService;
use App\Services\AssetService;
use App\Services\EmployeeAccountService;
use App\Services\LicenseService;
use App\Services\RequestProcessingService;
use App\Services\StockService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Data contoh lengkap (+50 per modul) untuk melihat contoh pemakaian aplikasi: karyawan ber-NIK (sebagian punya akun login, password awal = NIK),
 * aset + foto + dokumen + serah terima BAST, accessories / consumable / komponen + stok masuk & keluar, lisensi, IP, request ITERA beserta pencocokannya.
 * Semua lewat service aplikasi, hanya MENAMBAH data. Jalankan:  php artisan db:seed --class=SampleDataSeeder
 * Tanda: karyawan ber-NIK 2025xxx, aset serial SMP-..., barang bernama "... (contoh)" tidak ada; semua catatan memuat kata "Contoh".
 */
class SampleDataSeeder extends Seeder
{
    private const FIRST = ['Budi', 'Siti', 'Agus', 'Dewi', 'Rizky', 'Ayu', 'Hadi', 'Lestari', 'Joko', 'Wulan', 'Fikri', 'Nadia', 'Eka', 'Rina', 'Taufik', 'Intan', 'Bayu', 'Citra', 'Dimas', 'Fitri', 'Gilang', 'Hana', 'Irfan', 'Julia', 'Kevin'];
    private const LAST = ['Santoso', 'Wijaya', 'Hidayat', 'Putri', 'Nugraha', 'Saputra', 'Rahmawati', 'Kurniawan', 'Maulana', 'Permana', 'Utami', 'Setiawan', 'Pratama', 'Lestari', 'Ramadhan', 'Susanto', 'Anggraini', 'Firmansyah'];
    private const POSITIONS = ['Staf Administrasi', 'Supervisor Produksi', 'Staf Keuangan', 'Staf HRD', 'Surveyor', 'Operator Alat Berat', 'Staf Logistik', 'Staf Legal', 'Geologist', 'Staf Lingkungan', 'Staf Perencanaan', 'Koordinator Lapangan'];

    private StockService $stock;
    private AssetService $assets;

    public function run(): void
    {
        if (Setting::get('sample_data_seeded')) { $this->command?->warn('Data contoh sudah pernah dibuat.'); return; }
        $admin = User::where('email', 'admin@inventaris.test')->firstOrFail();
        Auth::setUser($admin);
        config(['mail.default' => 'array']);
        mt_srand(2027);
        $this->stock = app(StockService::class);
        $this->assets = app(AssetService::class);

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
        $dept = Department::pluck('id')->all();
        $loc = Location::pluck('id', 'name');
        $ven = Vendor::pluck('id', 'name');
        $emps = $this->employees($dept, $admin);
        $assets = $this->assets($loc, $ven, $emps);
        $acc = $this->accessories($loc, $ven, $emps);
        $con = $this->consumables($loc, $ven, $emps, $assets);
        $cmp = $this->components($loc, $ven, $assets);
        $this->licenses($ven, $emps, $assets);
        $this->ips($loc);
        $this->requests($emps, $assets, $acc, $con, $cmp);
        Setting::put('sample_data_seeded', now()->toDateTimeString());
        \Illuminate\Support\Facades\DB::commit();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();       // gagal di tengah = tidak ada data contoh yang tersimpan
            throw $e;
        }
    }

    private function photo(string $tag, string $title, array $rgb): string
    {
        $im = imagecreatetruecolor(640, 420);
        for ($y = 0; $y < 420; $y++) { $t = $y / 420; imageline($im, 0, $y, 640, $y, imagecolorallocate($im, (int) ($rgb[0] * (1 - $t * .5)), (int) ($rgb[1] * (1 - $t * .5)), (int) ($rgb[2] * (1 - $t * .5)))); }
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 150, 90, 490, 270, imagecolorallocatealpha($im, 255, 255, 255, 100));
        imagerectangle($im, 150, 90, 490, 270, $white);
        imagefilledrectangle($im, 90, 285, 550, 305, $white);
        imagestring($im, 5, 24, 340, $tag, $white);
        imagestring($im, 4, 24, 380, $title, $white);
        ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
        return $png;
    }

    private function pdf(string $title, array $rows): string
    {
        $html = '<html><body style="font-family:DejaVu Sans,sans-serif;font-size:12px"><h2 style="text-align:center">' . e($title) . '</h2><table border="1" cellpadding="6" cellspacing="0" width="100%">';
        foreach ($rows as $k => $v) $html .= '<tr><td width="32%">' . e($k) . '</td><td>' . e((string) $v) . '</td></tr>';
        return Pdf::loadHTML($html . '</table><p style="color:#888">Dokumen contoh (data contoh).</p></body></html>')->setPaper('a4')->output();
    }

    private function signature(): string
    {
        $im = imagecreatetruecolor(400, 160);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $ink = imagecolorallocate($im, 17, 24, 39); imagesetthickness($im, 3);
        $x = 30; $y = 90;
        for ($i = 0; $i < 28; $i++) { $nx = min(370, $x + mt_rand(8, 22)); $ny = max(25, min(135, $y + mt_rand(-40, 40))); imageline($im, $x, $y, $nx, $ny, $ink); [$x, $y] = [$nx, $ny]; }
        ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    // ------------------------------------------------------------------ karyawan (50, 30 punya akun login: username & password awal = NIK)
    private function employees(array $dept, User $admin): array
    {
        $svc = app(EmployeeAccountService::class);
        $out = [];
        for ($i = 1; $i <= 50; $i++) {
            $nik = '2025' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $name = self::FIRST[($i - 1) % count(self::FIRST)] . ' ' . self::LAST[intdiv($i - 1, count(self::FIRST)) % count(self::LAST)] . ($i > 25 ? ' ' . chr(64 + ($i - 25)) : '');
            $e = Employee::firstOrCreate(['nik' => $nik], [
                'name' => $name, 'email' => 'nik2025' . str_pad((string) $i, 3, '0', STR_PAD_LEFT) . '@akp.test', 'department_id' => $dept[$i % count($dept)],
                'position' => self::POSITIONS[$i % count(self::POSITIONS)], 'phone' => '0812' . str_pad((string) (3000000 + $i * 137), 7, '0', STR_PAD_LEFT), 'status' => 'active',
            ]);
            if ($i <= 30) $svc->apply($e, true, 'User', $admin);
            $out[] = $e->fresh();
        }
        return $out;
    }

    // ------------------------------------------------------------------ aset (50) + foto + invoice + serah terima/mutasi/kembali + perbaikan
    private function assets($loc, $ven, array $emps): array
    {
        $cats = Category::where('type', 'asset')->get()->keyBy('name');
        $defs = [
            ['Laptop', 'Lenovo', 'ThinkPad E14 Gen 5', 14500000, 'Intel Core i5-1335U', '16 GB DDR4', 'SSD NVMe 512 GB'], ['Laptop', 'Dell', 'Latitude 3440', 13200000, 'Intel Core i5-1235U', '16 GB DDR4', 'SSD 512 GB'],
            ['Laptop', 'HP', 'ProBook 440 G10', 12800000, 'Intel Core i5-1335U', '8 GB DDR4', 'SSD 256 GB'], ['Laptop', 'Asus', 'Vivobook 14', 9800000, 'AMD Ryzen 5 5500U', '8 GB DDR4', 'SSD 512 GB'],
            ['Laptop', 'Acer', 'Aspire 5', 9400000, 'Intel Core i5-1135G7', '8 GB DDR4', 'SSD 512 GB'], ['PC Desktop', 'Lenovo', 'ThinkCentre M70s', 10500000, 'Intel Core i5-12400', '16 GB DDR4', 'SSD 512 GB'],
            ['PC Desktop', 'HP', 'ProDesk 400 G9', 9900000, 'Intel Core i3-12100', '8 GB DDR4', 'SSD 256 GB'], ['Monitor', 'Dell', 'P2422H 24"', 2450000, null, null, null],
            ['Monitor', 'LG', '24MK600M 24"', 1850000, null, null, null], ['Monitor', 'Samsung', 'S27 27"', 2900000, null, null, null], ['Printer', 'Epson', 'L3250', 2750000, null, null, null],
            ['Printer', 'Canon', 'G3020', 2400000, null, null, null], ['Printer', 'Brother', 'DCP-T520W', 2650000, null, null, null], ['Perangkat Jaringan', 'TP-Link', 'Archer C6', 650000, null, null, null],
            ['Perangkat Jaringan', 'Mikrotik', 'hAP ac2', 1100000, null, null, null], ['UPS', 'APC', 'Back-UPS BX700', 1250000, null, null, null], ['Smartphone', 'Samsung', 'Galaxy A35', 5200000, null, null, null],
            ['Tablet', 'Samsung', 'Galaxy Tab A9', 2900000, null, null, null],
        ];
        $colors = ['Laptop' => [37, 99, 235], 'PC Desktop' => [79, 70, 229], 'Monitor' => [8, 145, 178], 'Printer' => [217, 119, 6], 'Perangkat Jaringan' => [5, 150, 105], 'UPS' => [101, 163, 13], 'Smartphone' => [124, 58, 237], 'Tablet' => [234, 88, 12]];
        $vendors = $ven->values()->all(); $locs = $loc->values()->all();
        $out = [];
        for ($i = 0; $i < 50; $i++) {
            [$c, $brand, $model, $price, $cpu, $ram, $hdd] = $defs[$i % count($defs)];
            if (! isset($cats[$c])) continue;
            $bought = now()->subMonths(mt_rand(1, 30))->subDays(mt_rand(0, 25));
            $spec = $cpu ? ['name' => 'PC-' . strtoupper(substr(md5("s$i"), 0, 5)), 'cpu' => $cpu, 'ram' => $ram, 'hdd' => $hdd, 'os' => 'Windows 11 Pro'] : null;
            $a = Asset::create(['asset_tag' => Asset::generateTag($cats[$c]), 'category_id' => $cats[$c]->id, 'brand' => $brand, 'model' => $model,
                'serial_number' => 'SMP-' . strtoupper(substr(md5("sample$i$model"), 0, 10)), 'specs' => $cpu ? "$cpu / $ram / $hdd" : 'Perangkat kantor standar (contoh)', 'spec_data' => $spec,
                'purchase_date' => $bought, 'vendor_id' => $vendors[$i % count($vendors)], 'po_pr_no' => 'PO/' . $bought->format('Y/m') . '/' . str_pad((string) (700 + $i), 4, '0', STR_PAD_LEFT),
                'purchase_price' => $price + mt_rand(-5, 5) * 10000, 'warranty_until' => $bought->copy()->addMonths(mt_rand(12, 36)), 'location_id' => $locs[$i % count($locs)],
                'status' => 'available', 'condition' => 'good', 'notes' => 'Contoh data aset ' . ($i + 1)]);
            $photo = 'assets/' . $a->asset_tag . '.png';
            Storage::disk('public')->put($photo, $this->photo($a->asset_tag, "$brand $model", $colors[$c] ?? [71, 85, 105]));
            $docs = ['asset-docs/' . $a->asset_tag . '-invoice.pdf', 'asset-docs/' . $a->asset_tag . '-garansi.pdf'];
            Storage::disk('public')->put($docs[0], $this->pdf('INVOICE PEMBELIAN', ['Barang' => "$brand $model", 'Serial Number' => $a->serial_number, 'No. PO/PR' => $a->po_pr_no, 'Harga' => 'Rp ' . number_format((float) $a->purchase_price, 0, ',', '.')]));
            Storage::disk('public')->put($docs[1], $this->pdf('KARTU GARANSI', ['Barang' => "$brand $model", 'Berlaku sampai' => $a->warranty_until->format('d/m/Y')]));
            $a->update(['photo_path' => $photo, 'documents' => $docs]);
            $out[] = $a;
        }
        $sig = $this->signature();
        // 30 aset diserahkan (BAST + tanda tangan digital); 6 dimutasi; 4 dikembalikan
        foreach (array_slice($out, 0, 30) as $k => $a) {
            $this->assets->checkout($a, $emps[$k % 50], ['signature_receiver' => $sig, 'signature_it' => $sig, 'notes' => 'Serah terima contoh', 'borrowed' => $k % 10 === 9]);
        }
        foreach (array_slice($out, 0, 6) as $k => $a) {
            $this->assets->transfer($a->fresh(), $emps[30 + $k], ['signature_receiver' => $sig, 'signature_it' => $sig, 'notes' => 'Mutasi contoh']);
        }
        foreach (array_slice($out, 6, 4) as $a) {
            $this->assets->checkin($a->fresh(), ['signature_receiver' => $sig, 'signature_it' => $sig, 'notes' => 'Pengembalian contoh', 'condition' => 'good']);
        }
        // perbaikan: 3 selesai (satu klaim garansi), 2 sedang diproses
        foreach ([[12, 'Layar berkedip', true], [13, 'Tidak bisa menyala', false], [14, 'Kipas berisik', false]] as [$idx, $issue, $warranty]) {
            $r = $this->assets->reportDamage($out[$idx], null, $issue . ' (contoh)');
            $r->update(['status' => 'in_progress']);
            $this->assets->finishRepair($r, ['action' => 'Perbaikan contoh selesai', 'cost' => $warranty ? 0 : mt_rand(1, 6) * 100000, 'technician_vendor' => 'Service Center Contoh', 'warranty_claim' => $warranty]);
        }
        foreach ([[40, 'Keyboard bermasalah'], [41, 'Baterai cepat habis']] as [$idx, $issue]) {
            $r = $this->assets->reportDamage($out[$idx], null, $issue . ' (contoh)');
            $r->update(['status' => 'in_progress', 'technician_vendor' => 'Service Center Contoh', 'action' => 'Menunggu sparepart']);
        }
        return $out;
    }

    // ------------------------------------------------------------------ accessories (50)
    private function accessories($loc, $ven, array $emps): array
    {
        $cat = Category::where('type', 'accessory')->get();
        $names = ['Mouse Wireless', 'Keyboard USB', 'Headset', 'Webcam HD', 'USB Hub', 'Kabel HDMI', 'Flashdisk', 'Charger Laptop', 'Mousepad', 'Laptop Stand', 'Kabel LAN', 'Card Reader', 'Speaker Mini', 'Adaptor VGA', 'Kabel Power'];
        $vendors = $ven->values()->all(); $svc = app(AccessoryHandoverService::class); $out = [];
        for ($i = 0; $i < 50; $i++) {
            $a = Accessory::create(['name' => $names[$i % count($names)] . ' ' . ['A', 'B', 'C', 'D'][intdiv($i, count($names)) % 4] . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 'category_id' => $cat[$i % $cat->count()]->id,
                'brand_model' => ['Logitech', 'Ugreen', 'Baseus', 'Orico', 'Sandisk'][$i % 5] . ' contoh', 'qty_total' => 0, 'qty_available' => 0, 'min_qty' => mt_rand(2, 8), 'location_id' => $loc['Gudang IT'] ?? $loc->first(),
                'unit_price' => mt_rand(4, 90) * 10000, 'vendor_id' => $vendors[$i % count($vendors)]]);
            $this->stock->move($a, 'in', mt_rand(15, 60), ['vendor_id' => $a->vendor_id, 'unit_price' => (float) $a->unit_price, 'received_at' => now()->subDays(mt_rand(30, 120))->toDateString(), 'reference_no' => 'PO-SMP-' . (100 + $i), 'reason' => 'Pembelian']);
            if ($i % 3 === 0) $this->stock->move($a, 'in', mt_rand(5, 20), ['vendor_id' => $vendors[($i + 2) % count($vendors)], 'unit_price' => (float) $a->unit_price + mt_rand(-2, 4) * 5000, 'received_at' => now()->subDays(mt_rand(1, 25))->toDateString(), 'reference_no' => 'PO-SMP-' . (300 + $i), 'reason' => 'Pembelian']);
            $out[] = $a->fresh();
        }
        foreach (array_slice($out, 0, 35) as $k => $a) {
            $h = $svc->checkout($emps[$k % 50], [['item' => 'accessory:' . $a->id, 'qty' => mt_rand(1, 3)]], null, 'Serah terima contoh');
            if ($k % 7 === 0) $svc->receiveBack($emps[$k % 50], [['accessory_id' => $a->id, 'qty' => 1, 'condition' => 'good']], 'Pengembalian contoh');
            if ($k % 5 === 0) {                                              // scan BAST bertanda tangan (dokumen)
                $path = 'accessory-bast-signed/contoh-' . $h->id . '.pdf';
                Storage::disk('local')->put($path, $this->pdf('SCAN BAST BERTANDA TANGAN', ['No. BAST' => $h->bast_number, 'Penerima' => $emps[$k % 50]->name]));
                $svc->attachSigned($h, $path, 'scan-' . str_replace('/', '-', $h->bast_number) . '.pdf', 'Scan contoh');
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ consumable (50)
    private function consumables($loc, $ven, array $emps, array $assets): array
    {
        $cat = Category::where('type', 'consumable')->get();
        $items = [['Tinta Epson 003 Hitam', 'pcs'], ['Tinta Canon GI-790', 'pcs'], ['Toner HP 85A', 'pcs'], ['Kertas A4 80gsm', 'rim'], ['Label Thermal 50x30', 'roll'], ['Kabel Ties 20cm', 'box'], ['Tisu Pembersih Layar', 'box'], ['Baterai AA', 'pcs'], ['Lakban Hitam', 'roll'], ['Kabel LAN Cat6', 'meter']];
        $vendors = $ven->values()->all(); $svc = app(AccessoryHandoverService::class); $out = [];
        for ($i = 0; $i < 50; $i++) {
            [$n, $u] = $items[$i % count($items)];
            $c = Consumable::create(['name' => $n . ' #' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 'category_id' => $cat[$i % $cat->count()]->id, 'unit' => $u, 'compatible_with' => 'Printer kantor (contoh)', 'qty' => 0, 'min_qty' => mt_rand(3, 10),
                'location_id' => $loc['Gudang IT'] ?? $loc->first(), 'unit_price' => mt_rand(2, 80) * 5000, 'vendor_id' => $vendors[$i % count($vendors)]]);
            $this->stock->move($c, 'in', mt_rand(20, 80), ['vendor_id' => $c->vendor_id, 'unit_price' => (float) $c->unit_price, 'received_at' => now()->subDays(mt_rand(20, 100))->toDateString(), 'reference_no' => 'PO-CSM-' . (100 + $i), 'reason' => 'Pembelian']);
            $out[] = $c->fresh();
        }
        foreach (array_slice($out, 0, 30) as $k => $c) {
            $t = ['employee', 'department', 'location', 'asset'][$k % 4];
            $svc->checkout($emps[$k % 50], [['item' => 'consumable:' . $c->id, 'qty' => in_array($c->unit, ['rim', 'roll', 'box', 'pcs'], true) ? mt_rand(1, 3) : mt_rand(2, 8) + 0.5]], null, 'Pengeluaran contoh', [
                'type' => $t, 'department_id' => Department::inRandomOrder()->value('id'), 'location_id' => $loc->first(), 'asset_id' => $assets[$k]->id]);
        }
        return $out;
    }

    // ------------------------------------------------------------------ komponen (50) + pemasangan ke laptop
    private function components($loc, $ven, array $assets): array
    {
        $cat = Category::where('type', 'component')->get();
        $items = [['RAM DDR4 8GB', 350000], ['RAM DDR4 16GB', 650000], ['SSD NVMe 512GB', 750000], ['SSD SATA 256GB', 450000], ['HDD 1TB', 650000], ['Baterai Laptop', 550000], ['Keyboard Laptop', 280000], ['Charger 65W', 320000], ['Kipas Prosesor', 150000], ['Layar LCD 14"', 1250000]];
        $vendors = $ven->values()->all(); $out = [];
        for ($i = 0; $i < 50; $i++) {
            [$n, $p] = $items[$i % count($items)];
            $c = Component::create(['name' => $n . ' (' . ['Kingston', 'Samsung', 'Crucial', 'WD', 'Generic'][$i % 5] . ') #' . ($i + 1), 'category_id' => $cat[$i % $cat->count()]->id, 'specs' => 'Komponen contoh untuk upgrade / perbaikan', 'qty_total' => 0, 'qty_available' => 0,
                'min_qty' => mt_rand(1, 3), 'location_id' => $loc['Gudang IT'] ?? $loc->first(), 'price' => $p, 'vendor_id' => $vendors[$i % count($vendors)], 'warranty_until' => now()->addMonths(mt_rand(6, 36))]);
            $this->stock->move($c, 'in', mt_rand(5, 20), ['vendor_id' => $c->vendor_id, 'unit_price' => (float) $p, 'received_at' => now()->subDays(mt_rand(20, 90))->toDateString(), 'reference_no' => 'PO-CMP-' . (100 + $i), 'reason' => 'Pembelian']);
            $out[] = $c->fresh();
        }
        $svc = app(AccessoryHandoverService::class);
        foreach ([[0, 0], [1, 1], [2, 2], [3, 3], [4, 4], [5, 5]] as [$ai, $ci]) {
            try { $svc->installComponents($assets[$ai]->fresh(), [['component_id' => $out[$ci]->id, 'qty' => 1]], [], 'Upgrade', 'Upgrade contoh'); } catch (\Throwable $e) { $this->command?->warn('Pemasangan komponen dilewati: ' . $e->getMessage()); }
        }
        return $out;
    }

    // ------------------------------------------------------------------ lisensi (20) dan IP (50)
    private function licenses($ven, array $emps, array $assets): void
    {
        $svc = app(LicenseService::class); $vendors = $ven->values()->all();
        $types = ['subscription', 'perpetual', 'domain', 'hosting', 'vps'];
        $names = ['Microsoft 365 Business', 'Adobe Creative Cloud', 'AutoCAD', 'Zoom Business', 'Slack Pro', 'Antivirus Endpoint', 'Windows 11 Pro', 'Office 2021', 'Canva Pro', 'Notion Team'];
        for ($i = 0; $i < 20; $i++) {
            $l = License::create(['name' => $names[$i % 10] . ' (contoh ' . ($i + 1) . ')', 'type' => $types[$i % 5], 'license_key' => 'SMP-' . strtoupper(substr(md5("lic$i"), 0, 12)), 'seats' => mt_rand(3, 15), 'purchase_date' => now()->subMonths(mt_rand(1, 10)),
                'expires_at' => now()->addDays([7, 25, 60, 120, 240][$i % 5]), 'price' => mt_rand(5, 90) * 100000, 'vendor_id' => $vendors[$i % count($vendors)]]);
            foreach (array_slice($emps, $i, 2 + $i % 3) as $e) { try { $svc->assign($l, $e); } catch (\Throwable $x) { } }
        }
    }

    private function ips(Location|\Illuminate\Support\Collection|null $loc): void
    {
        $locs = Location::pluck('id')->all();
        for ($i = 1; $i <= 50; $i++) {
            IpAddress::updateOrCreate(['ip_address' => '10.50.' . intdiv($i, 20) . '.' . (10 + $i % 20)], [
                'device_name' => ['Switch', 'Access Point', 'CCTV', 'Printer Jaringan', 'Server', 'Router'][$i % 6] . ' contoh ' . $i, 'username' => 'admin', 'password' => 'Contoh-' . substr(md5("ip$i"), 0, 10),
                'location_id' => $locs[$i % count($locs)], 'notes' => 'Data contoh']);
        }
    }

    // ------------------------------------------------------------------ request ITERA (50)
    private function requests(array $emps, array $assets, array $acc, array $con, array $cmp): void
    {
        $svc = app(RequestProcessingService::class);
        $types = ['ITR', 'TICKET_SISTEM', 'BAK', 'WO', 'PEMINJAMAN_ASET', 'ITR', 'TICKET_SISTEM', 'WO', 'ITR', 'PEMINJAMAN_ASET'];
        $titles = ['ITR' => 'Permintaan perangkat kerja', 'TICKET_SISTEM' => 'Kendala aplikasi / jaringan', 'BAK' => 'Berita acara kerusakan perangkat', 'WO' => 'Permintaan pekerjaan IT', 'PEMINJAMAN_ASET' => 'Peminjaman perangkat presentasi'];
        $seq = [];
        $ownerIds = User::whereIn('email', ['user@inventaris.test'])->pluck('id')->all();
        $borrowPool = array_values(array_filter($assets, fn ($a) => $a->fresh()->status === 'available' && $a->fresh()->category?->name !== 'Perangkat Jaringan'));
        $bp = 0;
        for ($i = 0; $i < 50; $i++) {
            $type = $types[$i % count($types)]; $e = $emps[$i % 30];
            $status = ['Pending', 'Approved', 'In Progress', 'Completed', 'Completed', 'Rejected', 'Approved', 'Pending', 'Completed', 'In Progress'][$i % 10];
            $when = now()->subDays(mt_rand(1, 60));
            $sn = null; $numbered = null;
            if (! in_array($status, ['Pending', 'Rejected'], true)) { $seq[$type][$when->format('ym')] = ($seq[$type][$when->format('ym')] ?? ITRequest::where('type', $type)->whereRaw("DATE_FORMAT(COALESCE(numbered_at, created_at), '%y%m') = ?", [$when->format('ym')])->max('sequence_number') ?? 0) + 1; $sn = $seq[$type][$when->format('ym')]; $numbered = $when; }
            $r = ITRequest::create(['type' => $type, 'sequence_number' => $sn, 'numbered_at' => $numbered, 'user_id' => $e->user_id, 'requester_name' => $e->name, 'nik' => $e->nik, 'department' => $e->department?->name ?? 'IT',
                'position' => $e->position, 'title' => $titles[$type] . ' #' . ($i + 1), 'category' => $type === 'TICKET_SISTEM' ? ['Jaringan', 'Aplikasi', 'Hardware'][$i % 3] : null, 'priority' => ['P1', 'P2', 'P3'][$i % 3],
                'qty' => 1, 'items' => $type === 'TICKET_SISTEM' ? null : [['name' => ['Laptop', 'Mouse Wireless', 'Monitor', 'Keyboard', 'Proyektor'][$i % 5], 'qty' => 1 + $i % 2, 'spesifikasi' => 'Sesuai kebutuhan (contoh)', 'keterangan' => 'Data contoh']],
                'description' => 'Contoh pengajuan yang diisi manual oleh pemohon.', 'status' => $status, 'approved_by' => $status === 'Pending' ? null : User::where('email', 'staff@inventaris.test')->value('id'),
                'approved_at' => $status === 'Pending' ? null : $when, 'completed_at' => $status === 'Completed' ? $when->copy()->addDays(2) : null,
                'loan_start_date' => $type === 'PEMINJAMAN_ASET' ? $when : null, 'loan_end_date' => $type === 'PEMINJAMAN_ASET' ? $when->copy()->addDays(3) : null,
                'analisa' => $type === 'BAK' ? 'Kerusakan akibat benturan (contoh)' : null, 'rekomendasi' => $type === 'BAK' ? 'Perbaikan / penggantian (contoh)' : null, 'created_at' => $when, 'updated_at' => $when]);
            // pencocokan oleh tim IT
            if ($type === 'ITR' && in_array($status, ['Approved', 'In Progress', 'Completed'], true)) {
                $svc->save($r, [], $e->id, [['kind' => 'accessory', 'item_id' => $acc[$i % 50]->id, 'qty' => 1, 'note' => 'Dari gudang IT']]);
            } elseif ($type === 'PEMINJAMAN_ASET' && in_array($status, ['Approved', 'In Progress'], true) && isset($borrowPool[$bp])) {
                $svc->save($r, [], $e->id, [['kind' => 'asset', 'item_id' => $borrowPool[$bp]->id, 'qty' => 1]]);
                try { $svc->borrow($r->fresh()); } catch (\Throwable $x) { $this->command?->warn('Peminjaman dilewati: ' . $x->getMessage()); }
                $bp++;
            }
        }
    }
}
