<?php

namespace Database\Seeders;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Department;
use App\Models\Disposal;
use App\Models\Employee;
use App\Models\License;
use App\Models\Location;
use App\Models\Repair;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AssetService;
use App\Services\ComponentService;
use App\Services\DisposalService;
use App\Services\LicenseService;
use App\Services\StockService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Data demo lengkap: master data, aset + foto + dokumen (invoice/garansi), serah terima bertanda tangan + BAST,
 * stok, komponen, lisensi, perbaikan, disposal, stock opname. Semua lewat service agar riwayat konsisten.
 * Jalankan: php artisan db:seed --class=DemoDataSeeder   (DEMO_FORCE=1 untuk memaksa di DB berisi)
 */
class DemoDataSeeder extends Seeder
{
    private AssetService $assets;
    private StockService $stock;
    private array $rentals = [];

    public function run(): void
    {
        if (Asset::count() > 4 && ! env('DEMO_FORCE')) {
            $this->command?->warn('Data demo sudah ada (set DEMO_FORCE=1 untuk memaksa).');
            return;
        }
        mt_srand(2026);
        $staff = User::where('email', 'staff@inventaris.test')->firstOrFail();
        $mgmt = User::where('email', 'admin@inventaris.test')->firstOrFail();   // approver pengajuan disposal (peran Manajemen dihapus)
        Auth::setUser($staff);
        $this->assets = app(AssetService::class);
        $this->stock = app(StockService::class);
        Storage::disk('public')->makeDirectory('assets');
        Storage::disk('public')->makeDirectory('asset-docs');

        [$dept, $loc, $ven, $cat] = $this->master();
        $emp = $this->employees($dept);
        $assets = $this->createAssets($cat, $loc, $ven);
        $this->handovers($assets, $emp, $loc);
        $this->rentalFlows($emp);
        $this->accessories($cat, $loc, $ven, $emp);
        $this->consumables($cat, $loc, $ven, $emp, $dept, $assets);
        $this->components($cat, $loc, $ven, $assets);
        $this->licenses($ven, $emp, $assets);
        $this->ipAddresses();
        $this->repairs($assets, $emp);
        $this->disposals($assets, $staff, $mgmt);
        $this->offboard($assets, $emp);
        $this->opnames($loc, $staff);
        $this->arrivalPlans($loc, $dept, $staff);
        Auth::setUser($staff);
        $this->command?->info('Data demo selesai: ' . Asset::count() . ' aset, ' . Employee::count() . ' karyawan.');
    }

    // ------------------------------------------------------------------ master
    private function master(): array
    {
        $dept = collect(['IT', 'Finance', 'HRD', 'Operasional', 'Marketing', 'Produksi', 'Legal'])
            ->mapWithKeys(fn ($n) => [$n => Department::firstOrCreate(['name' => $n])]);
        $loc = collect([
            ['Kantor Pusat', 'Pusat', 'Jl. Sam Ratulangi No. 10, Makassar'],
            ['Gudang IT', 'Pusat', 'Lantai 1, Kantor Pusat'],
            ['Ruang Server', 'Pusat', 'Lantai 2, Kantor Pusat'],
            ['Cabang Makassar', 'Makassar', 'Jl. Pettarani No. 22, Makassar'],
            ['Cabang Palu', 'Palu', 'Jl. Hasanuddin No. 5, Palu'],
        ])->mapWithKeys(fn ($r) => [$r[0] => Location::firstOrCreate(['name' => $r[0]], ['branch' => $r[1], 'address' => $r[2]])]);
        $ven = collect([
            ['PT Sumber Teknologi', 'Budi', '0811000000', 'sales@sumbertek.test'],
            ['CV Mitra Komputer', 'Hesti', '0812111222', 'order@mitrakomputer.test'],
            ['PT Global Network Indonesia', 'Andre', '0813222333', 'sales@globalnet.test'],
            ['Toko Sinar Jaya Printer', 'Koh Ahong', '0821333444', 'sinarjaya@toko.test'],
            ['PT Cloud Nusantara', 'Maya', '0822444555', 'cs@cloudnusantara.test'],
            ['PT Rental Teknologi Nusantara', 'Ferry', '0823555666', 'rental@rentek.test'],
            ['Toko Aksesori Prima', 'Linda', '0824666777', 'prima@aksesori.test'],
        ])->mapWithKeys(fn ($r) => [$r[0] => Vendor::firstOrCreate(['name' => $r[0]], ['contact_person' => $r[1], 'phone' => $r[2], 'email' => $r[3]])]);
        $cat = collect([
            ['Laptop', 'asset', 'NB'], ['Monitor', 'asset', 'MNT'], ['PC Desktop', 'asset', 'PC'], ['Printer', 'asset', 'PRT'],
            ['Perangkat Jaringan', 'asset', 'NET'], ['Server', 'asset', 'SRV'], ['UPS', 'asset', 'UPS'], ['Smartphone', 'asset', 'HP'], ['Tablet', 'asset', 'TAB'],
            ['Aksesoris', 'accessory', null], ['Kabel & Konektor', 'accessory', null],
            ['Tinta & Toner', 'consumable', null], ['Kertas & Label', 'consumable', null],
            ['Sparepart', 'component', null], ['Memori', 'component', null], ['Storage', 'component', null],
        ])->mapWithKeys(fn ($r) => [$r[0] => Category::firstOrCreate(['name' => $r[0], 'type' => $r[1]], ['code_prefix' => $r[2]])]);
        foreach (['Server' => 5, 'Smartphone' => 3, 'Tablet' => 3, 'Laptop' => 4, 'PC Desktop' => 4, 'Monitor' => 4, 'Printer' => 4, 'Perangkat Jaringan' => 4, 'UPS' => 4] as $name => $years) {
            Category::where('type', 'asset')->where('name', $name)->update(['useful_life_years' => $years]);
        }
        return [$dept, $loc, $ven, $cat];
    }

    private function employees($dept): array
    {
        $rows = [
            ['Andi Saputra', 'IT', 'IT Support'], ['Siti Rahma', 'Finance', 'Akuntan'], ['Rudi Hartono', 'HRD', 'HR Officer'],
            ['Rina Wulandari', 'IT', 'Staff IT'], ['Hendra Gunawan', 'Operasional', 'Manajer Operasional'], ['Maya Sari', 'Marketing', 'Marketing Executive'],
            ['Fajar Pratama', 'Marketing', 'Desainer Grafis'], ['Dewi Lestari', 'Finance', 'Kasir'], ['Agus Salim', 'Produksi', 'Supervisor Produksi'],
            ['Nur Aini', 'Legal', 'Legal Officer'], ['Yusuf Maulana', 'IT', 'Network Engineer'], ['Putri Ayu', 'HRD', 'Recruiter'],
            ['Bambang Irawan', 'Operasional', 'Staff Gudang'], ['Linda Kusuma', 'Finance', 'Manajer Keuangan'], ['Eko Prasetyo', 'Produksi', 'Operator'],
            ['Tania Basri', 'Marketing', 'Sales Manager'], ['Dedi Kurniawan', 'Operasional', 'Driver'], ['Wahyu Nugroho', 'IT', 'Kepala IT'],
        ];
        $out = [];
        foreach ($rows as [$n, $d, $pos]) {
            $email = str($n)->lower()->replace(' ', '.')->append('@inventaris.test')->toString();
            $out[$n] = Employee::where('name', $n)->first() ?? Employee::create([
                'nik' => (string) (2025000 + Employee::withTrashed()->count() + 1), 'email' => $email, 'name' => $n, 'department_id' => $dept[$d]->id, 'position' => $pos, 'phone' => '08' . mt_rand(1000000000, 9999999999), 'status' => 'active',
            ]);
        }
        // hubungkan akun login dengan data karyawan
        $out['Andi Saputra']->update(['user_id' => User::where('email', 'user@inventaris.test')->value('id')]);
        $out['Rina Wulandari']->update(['user_id' => User::where('email', 'staff@inventaris.test')->value('id')]);
        $out['Wahyu Nugroho']->update(['user_id' => User::where('email', 'admin@inventaris.test')->value('id')]);
        return $out;
    }

    // ------------------------------------------------------------------ aset
    private function createAssets($cat, $loc, $ven): array
    {
        // [kategori, merek, model, harga, bulan lalu beli, garansi (bln), lokasi, vendor, specs]
        $defs = [
            ['Laptop', 'Lenovo', 'ThinkPad E14 Gen 5', 12500000, 3, 36, 'Kantor Pusat', 'PT Sumber Teknologi', 'Intel Core i5-1335U, RAM 8GB, SSD 512GB, Windows 11 Pro'],
            ['Laptop', 'Lenovo', 'ThinkPad T14 Gen 4', 18900000, 5, 36, 'Kantor Pusat', 'PT Sumber Teknologi', 'Intel Core i7-1355U, RAM 16GB, SSD 512GB'],
            ['Laptop', 'HP', 'ProBook 440 G10', 11800000, 8, 36, 'Kantor Pusat', 'CV Mitra Komputer', 'Intel Core i5-1335U, RAM 8GB, SSD 256GB'],
            ['Laptop', 'HP', 'ProBook 450 G10', 12900000, 10, 12, 'Cabang Makassar', 'CV Mitra Komputer', 'Intel Core i5, RAM 16GB, SSD 512GB'],
            ['Laptop', 'Dell', 'Latitude 3440', 13400000, 12, 36, 'Kantor Pusat', 'PT Sumber Teknologi', 'Intel Core i5-1235U, RAM 8GB, SSD 256GB'],
            ['Laptop', 'Asus', 'ExpertBook B1 B1402', 9800000, 18, 24, 'Cabang Palu', 'CV Mitra Komputer', 'Intel Core i3, RAM 8GB, SSD 256GB'],
            ['Laptop', 'Acer', 'TravelMate P214', 8900000, 24, 24, 'Cabang Makassar', 'CV Mitra Komputer', 'AMD Ryzen 5, RAM 8GB, SSD 512GB'],
            ['Laptop', 'Apple', 'MacBook Air M2', 19500000, 6, 12, 'Kantor Pusat', 'PT Sumber Teknologi', 'Apple M2, RAM 8GB, SSD 256GB'],
            ['PC Desktop', 'Dell', 'OptiPlex 3000 SFF', 9700000, 14, 36, 'Kantor Pusat', 'PT Sumber Teknologi', 'Intel Core i5-12500, RAM 8GB, HDD 1TB'],
            ['PC Desktop', 'HP', 'ProDesk 400 G9', 9900000, 14, 36, 'Kantor Pusat', 'CV Mitra Komputer', 'Intel Core i5-12500, RAM 8GB, SSD 256GB'],
            ['PC Desktop', 'Lenovo', 'ThinkCentre M70s', 8700000, 20, 36, 'Cabang Makassar', 'CV Mitra Komputer', 'Intel Core i3-10100, RAM 8GB, HDD 1TB'],
            ['PC Desktop', 'Asus', 'ExpertCenter D500', 8200000, 30, 36, 'Cabang Palu', 'CV Mitra Komputer', 'Intel Core i3-12100, RAM 4GB, HDD 500GB'],
            ['Monitor', 'Dell', 'P2422H 24"', 2450000, 14, 36, 'Kantor Pusat', 'PT Sumber Teknologi', 'IPS Full HD 60Hz'],
            ['Monitor', 'Dell', 'P2422H 24"', 2450000, 14, 36, 'Kantor Pusat', 'PT Sumber Teknologi', 'IPS Full HD 60Hz'],
            ['Monitor', 'Dell', 'P2422H 24"', 2450000, 14, 36, 'Kantor Pusat', 'PT Sumber Teknologi', 'IPS Full HD 60Hz'],
            ['Monitor', 'LG', '24MK600M 24"', 1850000, 20, 12, 'Cabang Makassar', 'CV Mitra Komputer', 'IPS Full HD'],
            ['Monitor', 'AOC', '22B1HS 22"', 1350000, 26, 12, 'Cabang Palu', 'CV Mitra Komputer', 'IPS Full HD'],
            ['Monitor', 'Samsung', 'S24R350 24"', 1750000, 9, 24, 'Kantor Pusat', 'CV Mitra Komputer', 'IPS Full HD 75Hz'],
            ['Printer', 'HP', 'LaserJet Pro M404dn', 5900000, 16, 12, 'Kantor Pusat', 'Toko Sinar Jaya Printer', 'Mono laser A4, duplex, LAN'],
            ['Printer', 'Epson', 'L3250 EcoTank', 2900000, 7, 12, 'Cabang Makassar', 'Toko Sinar Jaya Printer', 'Inkjet tangki, WiFi'],
            ['Printer', 'Brother', 'HL-L2320D', 2300000, 22, 12, 'Cabang Palu', 'Toko Sinar Jaya Printer', 'Mono laser A4, duplex'],
            ['Printer', 'Canon', 'PIXMA G3010', 2100000, 11, 12, 'Kantor Pusat', 'Toko Sinar Jaya Printer', 'Inkjet tangki, WiFi'],
            ['Perangkat Jaringan', 'Mikrotik', 'RB4011iGS+RM', 4200000, 15, 12, 'Ruang Server', 'PT Global Network Indonesia', '10x Gigabit, SFP+'],
            ['Perangkat Jaringan', 'Cisco', 'SG350-28 Switch', 6800000, 15, 60, 'Ruang Server', 'PT Global Network Indonesia', '28-port managed switch'],
            ['Perangkat Jaringan', 'TP-Link', 'Archer AX55', 1250000, 6, 24, 'Cabang Palu', 'CV Mitra Komputer', 'WiFi 6 AX3000'],
            ['Perangkat Jaringan', 'Ubiquiti', 'UniFi U6 Lite AP', 1950000, 10, 12, 'Kantor Pusat', 'PT Global Network Indonesia', 'WiFi 6 Access Point'],
            ['Perangkat Jaringan', 'Fortinet', 'FortiGate 60F', 14500000, 13, 12, 'Ruang Server', 'PT Global Network Indonesia', 'Firewall UTM'],
            ['Server', 'Dell', 'PowerEdge R350', 38500000, 12, 36, 'Ruang Server', 'PT Sumber Teknologi', 'Xeon E-2334, RAM 32GB, 2x1TB SSD RAID1'],
            ['Server', 'HPE', 'ProLiant ML110 Gen10', 24500000, 28, 36, 'Ruang Server', 'PT Sumber Teknologi', 'Xeon Silver 4210, RAM 32GB, 4x2TB'],
            ['UPS', 'APC', 'Back-UPS BX1100LI', 1950000, 14, 24, 'Ruang Server', 'CV Mitra Komputer', '1100VA / 550W'],
            ['UPS', 'Prolink', 'PRO1201SFC', 1250000, 30, 12, 'Cabang Palu', 'CV Mitra Komputer', '1200VA'],
            ['Smartphone', 'Samsung', 'Galaxy A55', 6400000, 5, 12, 'Kantor Pusat', 'CV Mitra Komputer', '8/256GB'],
            ['Smartphone', 'Xiaomi', 'Redmi Note 13', 3200000, 4, 12, 'Kantor Pusat', 'CV Mitra Komputer', '8/256GB'],
            ['Tablet', 'Samsung', 'Galaxy Tab A9+', 3900000, 3, 12, 'Kantor Pusat', 'CV Mitra Komputer', '11", 4/64GB'],
            // untuk skenario disposal & perbaikan
            ['Laptop', 'Acer', 'Aspire 4741', 5500000, 60, 12, 'Gudang IT', 'CV Mitra Komputer', 'Intel Core i3 gen-1, RAM 2GB — sudah usang'],
            ['Monitor', 'Philips', '193V5 19"', 1100000, 48, 12, 'Gudang IT', 'CV Mitra Komputer', 'LCD 19" — panel bergaris'],
        ];
        $colors = ['Laptop' => [37, 99, 235], 'PC Desktop' => [79, 70, 229], 'Monitor' => [8, 145, 178], 'Printer' => [217, 119, 6], 'Perangkat Jaringan' => [5, 150, 105],
            'Server' => [190, 24, 93], 'UPS' => [101, 163, 13], 'Smartphone' => [124, 58, 237], 'Tablet' => [234, 88, 12]];
        $out = [];
        foreach ($defs as $i => [$c, $brand, $model, $price, $monthsAgo, $warr, $l, $v, $specs]) {
            $bought = now()->subMonths($monthsAgo)->subDays(mt_rand(0, 20));
            $asset = Asset::create([
                'asset_tag' => Asset::generateTag($cat[$c]), 'category_id' => $cat[$c]->id, 'brand' => $brand, 'model' => $model,
                'serial_number' => 'SN' . strtoupper(substr(md5("sn$i$brand$model"), 0, 10)), 'specs' => $specs,
                'purchase_date' => $bought, 'vendor_id' => $ven[$v]->id, 'po_pr_no' => 'PO/' . $bought->format('Y/m') . '/' . str_pad((string) (100 + $i), 4, '0', STR_PAD_LEFT),
                'purchase_price' => $price, 'warranty_until' => $bought->copy()->addMonths($warr), 'location_id' => $loc[$l]->id,
                'status' => 'available', 'condition' => 'good', 'notes' => null,
                'created_at' => now()->subMonths(min(5, intdiv($monthsAgo, 5)))->subDays(mt_rand(0, 25)),
            ]);
            $out[] = $asset;
        }
        // atur beberapa garansi agar muncul di peringatan (≤ 30 hari) & sudah lewat
        $out[2]->update(['warranty_until' => now()->addDays(12)]);
        $out[18]->update(['warranty_until' => now()->addDays(25)]);
        $out[24]->update(['warranty_until' => now()->addDays(6)]);
        $out[6]->update(['warranty_until' => now()->subDays(15)]);

        // aset sewa (rental): kategori, merek, model, biaya/bln, deposit, mulai (bulan lalu), berakhir (hari dari sekarang), lokasi, kontrak
        foreach ([
            ['Laptop', 'Lenovo', 'ThinkPad L14 (Sewa)', 650000, 1300000, 8, 480, 'Kantor Pusat', 'KTR-RNT-2026-001'],
            ['Laptop', 'Dell', 'Latitude 5440 (Sewa)', 700000, 1400000, 5, 600, 'Kantor Pusat', 'KTR-RNT-2026-002'],
            ['Printer', 'Ricoh', 'MP 2014 Multifungsi (Sewa)', 1200000, 2400000, 10, 12, 'Kantor Pusat', 'KTR-RNT-2026-003'],
            ['Laptop', 'HP', 'EliteBook 840 (Sewa)', 750000, 1500000, 13, -20, 'Cabang Makassar', 'KTR-RNT-2025-014'],
            ['Laptop', 'Asus', 'ExpertBook B5 (Sewa)', 600000, 1200000, 14, -60, 'Gudang IT', 'KTR-RNT-2025-009'],
        ] as $k => [$c, $brand, $model, $monthly, $deposit, $startAgo, $endIn, $l, $contract]) {
            $this->rentals[] = Asset::create([
                'asset_tag' => Asset::generateTag($cat[$c]), 'category_id' => $cat[$c]->id, 'brand' => $brand, 'model' => $model,
                'serial_number' => 'RT' . strtoupper(substr(md5("rental$k$model"), 0, 10)), 'specs' => 'Perangkat sewa dari vendor — ikuti kontrak ' . $contract,
                'ownership' => 'rental', 'vendor_id' => $ven['PT Rental Teknologi Nusantara']->id, 'rental_contract_no' => $contract,
                'rental_start' => now()->subMonths($startAgo), 'rental_end' => now()->addDays($endIn), 'rental_monthly_cost' => $monthly, 'rental_deposit' => $deposit,
                'purchase_date' => now()->subMonths($startAgo), 'purchase_price' => 0, 'location_id' => $loc[$l]->id, 'status' => 'available', 'condition' => 'good',
                'created_at' => now()->subMonths(min(5, intdiv($startAgo, 3)))->subDays(mt_rand(0, 20)),
            ]);
        }

        // semua aset tanpa foto (termasuk bawaan seeder dasar): lengkapi foto, dokumen, invoice
        foreach (Asset::with(['category', 'vendor'])->whereNull('photo_path')->get() as $a) {
            $a->forceFill([
                'po_pr_no' => $a->ownership === 'rental' ? null : ($a->po_pr_no ?: 'PO/' . ($a->purchase_date ?? now())->format('Y/m') . '/' . str_pad((string) (900 + $a->id), 4, '0', STR_PAD_LEFT)),
                'specs' => $a->specs ?: 'Spesifikasi standar perangkat kantor',
            ])->save();
            $rgb = $colors[$a->category->name] ?? [71, 85, 105];
            $photo = 'assets/' . $a->asset_tag . '.png';
            Storage::disk('public')->put($photo, $this->photo($a->asset_tag, $a->brand . ' ' . $a->model, $rgb));
            $docs = ['asset-docs/' . $a->asset_tag . '-invoice.pdf'];
            Storage::disk('public')->put($docs[0], $a->ownership === 'rental'
                ? $this->doc('KONTRAK SEWA', $a, "No. Kontrak: {$a->rental_contract_no}", 'Biaya sewa: Rp ' . number_format((float) $a->rental_monthly_cost, 0, ',', '.') . ' / bulan, berlaku s/d ' . $a->rental_end->format('d/m/Y'))
                : $this->doc('INVOICE PEMBELIAN', $a, "No. PO/PR: {$a->po_pr_no}", 'Total: Rp ' . number_format((float) $a->purchase_price, 0, ',', '.')));
            if ($a->warranty_until) {
                $docs[] = 'asset-docs/' . $a->asset_tag . '-garansi.pdf';
                Storage::disk('public')->put($docs[1], $this->doc('KARTU GARANSI', $a, 'Garansi resmi vendor', 'Berlaku sampai: ' . $a->warranty_until->format('d/m/Y')));
            }
            $a->update(['photo_path' => $photo, 'documents' => $docs]);
        }
        // spesifikasi bawaan tiap model diambil dari aset pertamanya
        foreach (\App\Models\AssetModel::whereNull('default_specs')->get() as $m) {
            $m->update(['default_specs' => Asset::where('asset_model_id', $m->id)->value('specs')]);
        }
        return $out;
    }

    private function photo(string $tag, string $title, array $rgb): string
    {
        $w = 640; $h = 420;
        $im = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $c = imagecolorallocate($im, (int) ($rgb[0] * (1 - $t * .5)), (int) ($rgb[1] * (1 - $t * .5)), (int) ($rgb[2] * (1 - $t * .5)));
            imageline($im, 0, $y, $w, $y, $c);
        }
        $white = imagecolorallocate($im, 255, 255, 255);
        $glass = imagecolorallocatealpha($im, 255, 255, 255, 100);
        imagefilledrectangle($im, 150, 90, 490, 270, $glass);
        imagerectangle($im, 150, 90, 490, 270, $white);
        imagefilledrectangle($im, 90, 285, 550, 305, $white);
        $this->bigText($im, $tag, 24, 335, 4, $white);
        $this->bigText($im, $title, 24, 380, 2, $white);
        ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
        return $png;
    }

    private function bigText($im, string $text, int $x, int $y, int $scale, $color): void
    {
        $tw = strlen($text) * 9; $th = 16;
        $tmp = imagecreatetruecolor($tw, $th);
        imagefill($tmp, 0, 0, imagecolorallocate($tmp, 0, 0, 0)); imagecolortransparent($tmp, imagecolorallocate($tmp, 0, 0, 0));
        imagestring($tmp, 5, 0, 0, $text, imagecolorallocate($tmp, 255, 255, 255));
        imagecopyresized($im, $tmp, $x, $y, 0, 0, (int) ($tw * $scale / 2), (int) ($th * $scale / 2), $tw, $th);
        imagedestroy($tmp);
    }

    private function doc(string $title, Asset $a, string $l1, string $l2): string
    {
        $html = '<html><body style="font-family:DejaVu Sans,sans-serif;font-size:12px"><h2 style="text-align:center">' . e($title) . '</h2>'
            . '<p>' . e($l1) . '</p><table border="1" cellpadding="6" cellspacing="0" width="100%">'
            . '<tr><td width="30%">Barang</td><td>' . e("{$a->brand} {$a->model}") . '</td></tr>'
            . '<tr><td>Serial Number</td><td>' . e($a->serial_number) . '</td></tr>'
            . '<tr><td>Tanggal</td><td>' . ($a->purchase_date?->format('d/m/Y')) . '</td></tr>'
            . '<tr><td>Vendor</td><td>' . e($a->vendor?->name) . '</td></tr></table><p><b>' . e($l2) . '</b></p>'
            . '<p style="color:#888">Dokumen contoh (data demo).</p></body></html>';
        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    private function signature(): string
    {
        $im = imagecreatetruecolor(400, 160);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $ink = imagecolorallocate($im, 17, 24, 39);
        imagesetthickness($im, 3);
        $x = 30; $y = 90;
        for ($i = 0; $i < 28; $i++) {
            $nx = min(370, $x + mt_rand(8, 22)); $ny = max(25, min(135, $y + mt_rand(-40, 40)));
            imageline($im, $x, $y, $nx, $ny, $ink);
            [$x, $y] = [$nx, $ny];
        }
        ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    // ------------------------------------------------------------------ serah terima
    private function handovers(array $a, array $e, $loc): void
    {
        $assign = function (int $idx, string $who, int $daysAgo, array $extra = []) use ($a, $e) {
            $this->assets->checkout($a[$idx], $e[$who], $extra + [
                'condition' => 'good', 'assigned_at' => now()->subDays($daysAgo),
                'signature_receiver' => $this->signature(), 'signature_it' => $this->signature(),
                'notes' => 'Serah terima awal',
            ]);
        };
        $plan = [
            [0, 'Andi Saputra', 80], [1, 'Wahyu Nugroho', 120], [2, 'Siti Rahma', 60], [3, 'Hendra Gunawan', 75], [4, 'Linda Kusuma', 90],
            [5, 'Nur Aini', 40], [7, 'Fajar Pratama', 30], [8, 'Dewi Lestari', 100], [9, 'Putri Ayu', 55], [10, 'Bambang Irawan', 70],
            [12, 'Andi Saputra', 80], [13, 'Wahyu Nugroho', 120], [14, 'Siti Rahma', 60], [17, 'Fajar Pratama', 30],
            [31, 'Tania Basri', 20], [32, 'Maya Sari', 15], [6, 'Agus Salim', 45],
        ];
        foreach ($plan as [$i, $who, $d]) $assign($i, $who, $d);

        $assign(33, 'Maya Sari', 6, ['borrowed' => true, 'notes' => 'Dipinjam untuk event pameran']);   // Tablet: dipinjam
        $this->assets->transfer($a[3], $e['Eko Prasetyo'], ['condition' => 'good', 'assigned_at' => now()->subDays(25), 'signature_receiver' => $this->signature(), 'signature_it' => $this->signature(), 'notes' => 'Mutasi ke Produksi']);
        $this->assets->transfer($a[9], $e['Rudi Hartono'], ['condition' => 'good', 'assigned_at' => now()->subDays(10), 'signature_receiver' => $this->signature(), 'signature_it' => $this->signature()]);
        $this->assets->transfer($a[17], $e['Tania Basri'], ['condition' => 'good', 'assigned_at' => now()->subDays(5), 'signature_receiver' => $this->signature(), 'signature_it' => $this->signature()]);
        // pengembalian: monitor 14 kembali dari Siti dengan lecet ringan
        $this->assets->checkin($a[14], ['condition' => 'minor_damage', 'assigned_at' => now()->subDays(3), 'location_id' => $loc['Gudang IT']->id, 'notes' => 'Bezel lecet', 'signature_receiver' => $this->signature(), 'signature_it' => $this->signature()]);
        // aset hilang
        $a[32]->update(['status' => 'lost', 'notes' => 'Dilaporkan hilang saat dinas luar kota']);
    }

    // ------------------------------------------------------------------ aset sewa
    private function rentalFlows(array $e): void
    {
        $r = $this->rentals;
        $d = fn (int $days) => ['condition' => 'good', 'assigned_at' => now()->subDays($days), 'signature_receiver' => $this->signature(), 'signature_it' => $this->signature(), 'notes' => 'Aset sewa'];
        $this->assets->checkout($r[0], $e['Maya Sari'], $d(60));
        $this->assets->checkout($r[1], $e['Tania Basri'], $d(40));
        $this->assets->checkout($r[3], $e['Agus Salim'], $d(300));            // sewa sudah lewat jatuh tempo, masih dipakai
        // r[2] (printer) tersedia dan sewanya segera berakhir; r[4] dipakai lalu dikembalikan ke vendor
        $this->assets->checkout($r[4], $e['Eko Prasetyo'], $d(400));
        $this->assets->checkin($r[4], ['condition' => 'good', 'assigned_at' => now()->subDays(65), 'signature_receiver' => $this->signature(), 'signature_it' => $this->signature()]);
        $this->assets->returnToVendor($r[4]->fresh(), ['returned_at' => now()->subDays(60)->toDateString(), 'condition' => 'good', 'data_wiped' => true, 'notes' => 'BA pengembalian ke vendor no. BA-RNT-009']);
    }

    // ------------------------------------------------------------------ accessories
    private function accessories($cat, $loc, $ven, array $e): void
    {
        $defs = [
            ['Mouse Wireless Logitech M331', 'Aksesoris', 'Logitech M331', 40, 8, 165000],
            ['Keyboard Logitech K120', 'Aksesoris', 'Logitech K120', 30, 6, 135000],
            ['Headset Jabra Evolve 20', 'Aksesoris', 'Jabra Evolve 20', 12, 4, 890000],
            ['Webcam Logitech C920', 'Aksesoris', 'Logitech C920', 8, 3, 1250000],
            ['USB Hub 4-Port', 'Aksesoris', 'Orico', 25, 5, 85000],
            ['Kabel HDMI 2 meter', 'Kabel & Konektor', 'Ugreen', 30, 8, 65000],
            ['Flashdisk 64GB', 'Aksesoris', 'Sandisk Ultra', 20, 5, 95000],
            ['Charger Laptop Universal 65W', 'Kabel & Konektor', 'Baseus', 6, 3, 320000],
        ];
        $objs = [];
        foreach ($defs as $i => [$name, $c, $bm, $qty, $min, $price]) {
            $acc = Accessory::create(['name' => $name, 'category_id' => $cat[$c]->id, 'brand_model' => $bm, 'qty_total' => 0, 'qty_available' => 0, 'min_qty' => $min,
                'location_id' => $loc['Gudang IT']->id, 'unit_price' => $price, 'vendor_id' => $ven['CV Mitra Komputer']->id]);
            $this->stock->move($acc, 'in', $qty, ['reason' => 'Pembelian', 'notes' => 'Stok awal', 'moved_at' => now()->subDays(90)]);
            $objs[$i] = $acc;
        }
        $give = [[0, 'Andi Saputra', 1], [0, 'Siti Rahma', 1], [0, 'Wahyu Nugroho', 1], [0, 'Fajar Pratama', 1], [0, 'Linda Kusuma', 1], [0, 'Maya Sari', 1], [0, 'Dewi Lestari', 1],
            [1, 'Andi Saputra', 1], [1, 'Siti Rahma', 1], [1, 'Dewi Lestari', 1], [2, 'Maya Sari', 1], [2, 'Tania Basri', 1], [2, 'Putri Ayu', 1], [2, 'Linda Kusuma', 1], [2, 'Hendra Gunawan', 1], [2, 'Nur Aini', 1], [2, 'Rudi Hartono', 1], [2, 'Fajar Pratama', 1],
            [3, 'Maya Sari', 1], [3, 'Tania Basri', 1], [3, 'Putri Ayu', 1], [3, 'Linda Kusuma', 1], [3, 'Hendra Gunawan', 1], [3, 'Rudi Hartono', 1],
            [4, 'Fajar Pratama', 2], [5, 'Wahyu Nugroho', 2], [7, 'Hendra Gunawan', 1], [7, 'Linda Kusuma', 1], [7, 'Maya Sari', 1], [7, 'Tania Basri', 1],
            [6, 'Dedi Kurniawan', 1], [0, 'Dedi Kurniawan', 1]];
        foreach ($give as $k => [$i, $who, $q]) {
            $this->stock->move($objs[$i], 'out', $q, ['employee_id' => $e[$who]->id, 'reason' => 'Checkout', 'moved_at' => now()->subDays(60 - $k)]);
        }
        // satu pengembalian dengan kondisi
        $this->stock->move($objs[1], 'return', 1, ['employee_id' => $e['Dewi Lestari']->id, 'reason' => 'Checkin', 'condition' => 'good', 'moved_at' => now()->subDays(4)]);
        // pembelian berulang untuk barang yang sama: vendor & harga berbeda tiap kali (riwayat tidak saling menimpa)
        $this->stock->move($objs[0], 'in', 15, ['vendor_id' => $ven['Toko Aksesori Prima']->id, 'unit_price' => 152000, 'reference_no' => 'PO-2026-061', 'received_at' => now()->subDays(7)->toDateString(), 'reason' => 'Pembelian', 'notes' => 'Pembelian kedua, vendor berbeda']);
        $this->stock->move($objs[0], 'in', 10, ['vendor_id' => $ven['PT Sumber Teknologi']->id, 'unit_price' => 171000, 'reference_no' => 'PO-2026-073', 'received_at' => now()->subDays(1)->toDateString(), 'reason' => 'Pembelian']);
        $this->stock->move($objs[3], 'in', 4, ['vendor_id' => $ven['PT Global Network Indonesia']->id, 'unit_price' => 1180000, 'reference_no' => 'PO-2026-070', 'received_at' => now()->subDays(12)->toDateString(), 'reason' => 'Pembelian']);
        // tambah stok lagi (pembelian kedua)
        $this->stock->move($objs[4], 'in', 10, ['reason' => 'Pembelian', 'notes' => 'PO-2026-044', 'moved_at' => now()->subDays(20)]);
    }

    // ------------------------------------------------------------------ consumables
    private function consumables($cat, $loc, $ven, array $e, $dept, array $assets): void
    {
        $defs = [
            ['Toner HP 85A (CE285A)', 'Tinta & Toner', 'pcs', 'HP LaserJet P1102, M1132, M1212, Pro M12', 12, 3, 850000],
            ['Toner HP 78A (CE278A)', 'Tinta & Toner', 'pcs', 'HP LaserJet P1566, P1606, M1536', 6, 2, 890000],
            ['Toner HP 58A (CF258A)', 'Tinta & Toner', 'pcs', 'HP LaserJet Pro M404dn, M428', 4, 2, 1450000],
            ['Tinta Epson 003 Black', 'Tinta & Toner', 'pcs', 'Epson L1110, L3110, L3210, L3250', 14, 4, 95000],
            ['Tinta Canon GI-790 Black', 'Tinta & Toner', 'pcs', 'Canon G1010, G2010, G3010', 8, 3, 85000],
            ['Kertas A4 80gsm', 'Kertas & Label', 'rim', 'Semua printer A4', 60, 15, 52000],
            ['Kabel UTP Cat6', 'Kertas & Label', 'roll', 'Instalasi jaringan LAN', 3, 1, 1650000],
            ['Roll Label Thermal 50x30', 'Kertas & Label', 'roll', 'Printer label Zebra / TSC', 10, 4, 38000],
        ];
        $objs = [];
        foreach ($defs as $i => [$name, $c, $unit, $compat, $qty, $min, $price]) {
            $co = Consumable::create(['name' => $name, 'category_id' => $cat[$c]->id, 'unit' => $unit, 'compatible_with' => $compat, 'qty' => 0, 'min_qty' => $min,
                'location_id' => $loc['Gudang IT']->id, 'unit_price' => $price, 'vendor_id' => $ven['Toko Sinar Jaya Printer']->id]);
            $this->stock->move($co, 'in', $qty, ['reason' => 'Pembelian', 'notes' => 'Stok awal', 'moved_at' => now()->subDays(100)]);
            $objs[$i] = $co;
        }
        $printerHp = $assets[18]; $printerEpson = $assets[19]; $printerBrother = $assets[20]; $printerCanon = $assets[21];
        $out = [
            [0, 5, ['asset_id' => $printerBrother->id], 70], [0, 2, ['employee_id' => $e['Siti Rahma']->id], 40], [2, 2, ['asset_id' => $printerHp->id], 50], [2, 1, ['asset_id' => $printerHp->id], 12],
            [3, 6, ['asset_id' => $printerEpson->id], 55], [3, 2, ['department_id' => $dept['Marketing']->id], 25], [4, 4, ['asset_id' => $printerCanon->id], 45], [4, 1, ['department_id' => $dept['Finance']->id], 9],
            [5, 25, ['department_id' => $dept['Finance']->id], 80], [5, 20, ['department_id' => $dept['HRD']->id], 50], [5, 8, ['department_id' => $dept['Operasional']->id], 18], [5, 4, ['department_id' => $dept['Marketing']->id], 6],
            [6, 2, ['employee_id' => $e['Yusuf Maulana']->id], 35], [7, 7, ['department_id' => $dept['Operasional']->id], 28],
        ];
        foreach ($out as [$i, $q, $meta, $d]) {
            $this->stock->move($objs[$i], 'out', $q, $meta + ['reason' => 'Pemakaian', 'notes' => 'Pemakaian rutin', 'moved_at' => now()->subDays($d)]);
        }
        $this->stock->move($objs[0], 'in', 6, ['vendor_id' => $ven['PT Sumber Teknologi']->id, 'unit_price' => 790000, 'reference_no' => 'PO-2026-066', 'received_at' => now()->subDays(9)->toDateString(), 'reason' => 'Pembelian']);
        $this->stock->move($objs[5], 'in', 30, ['reason' => 'Pembelian', 'notes' => 'PO-2026-051', 'moved_at' => now()->subDays(14)]);
    }

    // ------------------------------------------------------------------ komponen
    private function components($cat, $loc, $ven, array $assets): void
    {
        $existing = Component::first();   // RAM DDR4 8GB dari seeder dasar
        $defs = [
            ['RAM DDR4 16GB', 'Memori', 'DDR4 3200MHz SODIMM 16GB', 6, 2, 850000],
            ['SSD NVMe 512GB', 'Storage', 'M.2 2280 NVMe Gen3', 8, 3, 780000],
            ['SSD SATA 480GB', 'Storage', '2.5" SATA III', 6, 2, 620000],
            ['HDD 1TB 3.5"', 'Storage', 'SATA 7200rpm', 4, 2, 780000],
            ['Power Supply 450W', 'Sparepart', 'ATX 80+ White', 3, 1, 420000],
            ['Baterai Laptop ThinkPad E14', 'Sparepart', 'Original 45Wh', 3, 1, 1250000],
        ];
        $o = [];
        foreach ($defs as $i => [$n, $c, $spec, $q, $min, $price]) {
            $o[$i] = Component::create(['name' => $n, 'category_id' => $cat[$c]->id, 'specs' => $spec, 'qty_total' => 0, 'qty_available' => 0, 'min_qty' => $min,
                'location_id' => $loc['Gudang IT']->id, 'price' => $price, 'vendor_id' => $ven['CV Mitra Komputer']->id, 'warranty_until' => now()->addMonths(12)]);
            $this->stock->move($o[$i], 'in', $q, ['reason' => 'Pembelian', 'notes' => 'Stok awal', 'moved_at' => now()->subDays(60)]);
        }
        $this->stock->move($o[0], 'in', 4, ['vendor_id' => $ven['PT Global Network Indonesia']->id, 'unit_price' => 820000, 'reference_no' => 'PO-2026-068', 'received_at' => now()->subDays(15)->toDateString(), 'reason' => 'Pembelian']);
        $svc = app(ComponentService::class);
        // upgrade RAM: ThinkPad E14 -> RAM 8GB dipasang dulu (komponen awal), lalu diganti 16GB (lepas 8GB)
        $svc->install($existing, $assets[0], 1, 'Upgrade');
        $svc->install($o[0], $assets[0], 1, 'Upgrade', $existing, 1, 'Dipindah ke stok, kondisi baik');
        $svc->install($o[1], $assets[8], 1, 'Upgrade');       // OptiPlex + SSD
        $svc->install($o[5], $assets[2], 1, 'Perbaikan');     // baterai HP ProBook
        $svc->install($o[4], $assets[10], 1, 'Perbaikan');    // PSU ThinkCentre
    }

    // ------------------------------------------------------------------ lisensi
    /** Daftar IP perangkat jaringan/server (password demo acak; hanya untuk data uji). */
    private function ipAddresses(): void
    {
        $loc = \App\Models\Location::pluck('id', 'name');
        foreach ([
            ['Router Utama', '192.168.1.1', 'admin', 'Kantor Pusat'], ['Switch Lantai 1', '192.168.1.2', 'admin', 'Kantor Pusat'],
            ['Switch Lantai 2', '192.168.1.3', 'netadmin', 'Kantor Pusat'], ['Access Point Lobby', '192.168.1.20', 'apadmin', 'Kantor Pusat'],
            ['NVR CCTV Gudang', '192.168.10.50', 'cctv', 'Gudang IT'], ['Server File', '192.168.1.100', 'root', 'Kantor Pusat'],
            ['Router Cabang Makassar', '10.20.0.1', 'admin', 'Cabang Makassar'], ['Router Cabang Palu', '10.30.0.1', 'admin', 'Cabang Palu'],
        ] as $k => [$name, $ip, $user, $l]) {
            \App\Models\IpAddress::updateOrCreate(['ip_address' => $ip], [
                'device_name' => $name, 'username' => $user, 'password' => 'Demo-' . substr(md5("ip$k$ip"), 0, 10),
                'location_id' => $loc[$l] ?? null, 'notes' => $k === 0 ? 'Gateway internet utama' : null,
            ]);
        }
    }

    private function licenses($ven, array $e, array $assets): void
    {
        $svc = app(LicenseService::class);
        $m365 = License::first();
        foreach (['Andi Saputra', 'Siti Rahma', 'Linda Kusuma', 'Wahyu Nugroho'] as $n) $svc->assign($m365, $e[$n]);

        $mk = fn (array $d) => License::create($d + ['vendor_id' => $ven['PT Cloud Nusantara']->id]);
        $adobe = $mk(['name' => 'Adobe Acrobat Pro', 'type' => 'subscription', 'license_key' => 'ACRO-DEMO-2026', 'seats' => 3, 'purchase_date' => now()->subMonths(4), 'expires_at' => now()->addMonths(8), 'price' => 4800000]);
        foreach (['Nur Aini', 'Linda Kusuma'] as $n) $svc->assign($adobe, $e[$n]);

        $win = $mk(['name' => 'Windows 11 Pro (Volume)', 'type' => 'perpetual', 'license_key' => 'WIN11-DEMO-KEY', 'seats' => 20, 'purchase_date' => now()->subMonths(10), 'price' => 36000000]);
        foreach ([0, 1, 2, 3, 4, 7, 8, 9] as $i) $svc->assign($win, null, $assets[$i]);

        $kas = $mk(['name' => 'Kaspersky Endpoint Security', 'type' => 'subscription', 'license_key' => 'KES-DEMO-0001', 'seats' => 30, 'purchase_date' => now()->subMonths(11), 'expires_at' => now()->addDays(7), 'price' => 15500000]);
        foreach ([0, 1, 2, 3, 4, 5, 7] as $i) $svc->assign($kas, null, $assets[$i]);

        $dom = $mk(['name' => 'Domain perusahaan.co.id', 'type' => 'domain', 'license_key' => null, 'seats' => 1, 'purchase_date' => now()->subMonths(11), 'expires_at' => now()->addDays(1), 'price' => 350000, 'notes' => 'Registrar: Niagahoster. Perpanjang otomatis NONAKTIF.']);
        $svc->assign($dom, $e['Wahyu Nugroho']);

        $host = $mk(['name' => 'Hosting cPanel Business', 'type' => 'hosting', 'license_key' => 'cpanel-demo-pass', 'seats' => 1, 'purchase_date' => now()->subMonths(6), 'expires_at' => now()->addDays(60), 'price' => 1800000]);
        $svc->assign($host, $e['Yusuf Maulana']);

        $vps = $mk(['name' => 'VPS Biznet Gio 4vCPU/8GB', 'type' => 'vps', 'license_key' => null, 'seats' => 1, 'purchase_date' => now()->subMonths(11), 'expires_at' => now()->addDays(3), 'price' => 5400000, 'notes' => 'Menjalankan aplikasi internal']);
        $svc->assign($vps, null, $assets[27]);

        // lisensi dengan seat berlebih (skenario peringatan): turunkan jumlah seat setelah alokasi
        $zoom = $mk(['name' => 'Zoom Pro', 'type' => 'subscription', 'license_key' => 'ZOOM-DEMO', 'seats' => 3, 'purchase_date' => now()->subMonths(2), 'expires_at' => now()->addMonths(10), 'price' => 2900000]);
        foreach (['Maya Sari', 'Tania Basri', 'Hendra Gunawan'] as $n) $svc->assign($zoom, $e[$n]);
        $zoom->update(['seats' => 2]);
    }

    // ------------------------------------------------------------------ perbaikan
    private function repairs(array $a, array $e): void
    {
        // 1) selesai + klaim garansi
        $r = $this->assets->reportDamage($a[1], $e['Wahyu Nugroho'], 'Layar berkedip dan muncul garis vertikal');
        $r->update(['reported_at' => now()->subDays(40), 'status' => 'in_progress']);
        $this->assets->finishRepair($r, ['action' => 'Ganti panel LCD (klaim garansi vendor)', 'cost' => 0, 'technician_vendor' => 'Lenovo Service Center', 'warranty_claim' => true]);
        $r->update(['finished_at' => now()->subDays(33)]);

        // 2) selesai berbayar
        $r = $this->assets->reportDamage($a[8], $e['Dewi Lestari'], 'PC sering restart sendiri');
        $r->update(['reported_at' => now()->subDays(22)]);
        $this->assets->finishRepair($r, ['action' => 'Pembersihan debu, ganti thermal paste', 'cost' => 150000, 'technician_vendor' => 'Andi Saputra (internal)', 'warranty_claim' => false]);
        $r->update(['finished_at' => now()->subDays(20)]);

        // 3) sedang diproses
        $r = $this->assets->reportDamage($a[18], null, 'Kertas sering macet dan hasil cetak bergaris');
        $r->update(['reported_at' => now()->subDays(5), 'status' => 'in_progress', 'action' => 'Menunggu sparepart roller', 'technician_vendor' => 'Toko Sinar Jaya Printer']);

        // 4) baru dilaporkan oleh karyawan
        $this->assets->reportDamage($a[7], $e['Fajar Pratama'], 'Keyboard beberapa tombol tidak berfungsi');

        // 5) lebih dari 14 hari belum selesai
        $r = $this->assets->reportDamage($a[17], $e['Tania Basri'], 'Monitor tidak menyala, lampu indikator kedip');
        $r->update(['reported_at' => now()->subDays(20), 'status' => 'in_progress', 'technician_vendor' => 'CV Mitra Komputer']);
    }

    // ------------------------------------------------------------------ disposal
    private function disposals(array $a, User $staff, User $mgmt): void
    {
        $svc = app(DisposalService::class);
        Auth::setUser($staff);
        $d1 = $svc->request($a[34]->id, 'Laptop usang (2012), tidak bisa menjalankan OS saat ini', true);
        $d2 = $svc->request($a[35]->id, 'Panel monitor bergaris permanen, biaya perbaikan melebihi nilai', true);
        $d3 = $svc->request($a[20]->id, 'Printer lama — usulan pergantian', true);
        Auth::setUser($mgmt);
        $svc->approve($d1, 'ewaste');
        $svc->reject($d3);
        Auth::setUser($staff);
        // $d2 dibiarkan pending agar muncul di antrean approval
    }

    // ------------------------------------------------------------------ resign sebagian
    private function offboard(array $a, array $e): void
    {
        $dedi = $e['Dedi Kurniawan'];
        $this->assets->checkout($a[11], $dedi, ['condition' => 'good', 'assigned_at' => now()->subDays(50), 'signature_receiver' => $this->signature(), 'signature_it' => $this->signature()]);
        // offboarding sebagian: laptop kembali, accessories (mouse, flashdisk) belum
        $this->assets->bulkCheckin($dedi, [$a[11]->id => 'good'], ['assigned_at' => now()->subDays(2)]);
        $dedi->update(['status' => 'resigned']);
    }

    // ------------------------------------------------------------------ rencana kedatangan karyawan onsite
    private function arrivalPlans($loc, $dept, User $staff): void
    {
        Auth::setUser($staff);
        $svc = app(\App\Services\ArrivalPlanService::class);
        $free = Asset::where('status', 'available')->whereNull('employee_id')->where('ownership', 'owned')->whereDoesntHave('reservationItem')->orderByDesc('id')->get()->values();
        $take = fn () => $free->shift();
        $mouse = Accessory::where('name', 'Mouse Wireless Logitech M331')->first();
        $kbd = Accessory::where('name', 'Keyboard Logitech K120')->first();
        $mk = fn (array $d) => $svc->create($d + ['pic_id' => $staff->id, 'location_id' => $loc['Cabang Palu']->id]);
        $ready = function ($plan, bool $all = true) use ($svc) {
            foreach ($plan->items()->get() as $i => $it) {
                if (! $all && $i > 0) break;
                $svc->setItemStatus($it, 'prepared');
                $svc->setItemStatus($it->fresh(), 'ready');
            }
        };

        // 1) Siap: tiba 3 hari lagi
        $p1 = $mk(['name' => 'Ahmad Fauzi', 'nik' => '2026001', 'email' => 'ahmad.fauzi@inventaris.test', 'phone' => '081234500001', 'department_id' => $dept['Produksi']->id,
            'position' => 'Operator Alat Berat', 'arrival_date' => now()->addDays(3)->toDateString(), 'notes' => 'Penempatan site Palu, mulai shift pagi.']);
        foreach ([$take(), $take()] as $a) if ($a) $svc->addAsset($p1, $a);
        if ($mouse) $svc->addAccessory($p1, $mouse, 1);
        if ($kbd) $svc->addAccessory($p1, $kbd, 1);
        $ready($p1);

        // 2) Disiapkan sebagian: tiba 6 hari lagi (belum semua siap)
        $p2 = $mk(['name' => 'Rizky Ramadhan', 'phone' => '081234500002', 'department_id' => $dept['IT']->id, 'position' => 'Network Engineer',
            'arrival_date' => now()->addDays(6)->toDateString(), 'notes' => 'Email diisi saat tiba.']);
        if ($a = $take()) $svc->addAsset($p2, $a);
        if ($a = $take()) $svc->addAsset($p2, $a);
        if ($mouse) $svc->addAccessory($p2, $mouse, 1);
        $ready($p2, false);

        // 3) Direncanakan: tiba 12 hari lagi
        $p3 = $mk(['name' => 'Sinta Dewi', 'phone' => '081234500003', 'department_id' => $dept['Finance']->id, 'position' => 'Staff Akuntansi',
            'arrival_date' => now()->addDays(12)->toDateString()]);
        if ($a = $take()) $svc->addAsset($p3, $a);

        // 4) Diserahkan (karyawan sudah tiba): BAST terbit, stok berkurang
        $p4 = $mk(['name' => 'Budi Santoso', 'email' => 'budi.santoso@inventaris.test', 'phone' => '081234500004', 'department_id' => $dept['Operasional']->id,
            'position' => 'Supervisor Lapangan', 'arrival_date' => now()->toDateString(), 'notes' => 'Penempatan baru.']);
        if ($a = $take()) $svc->addAsset($p4, $a);
        if ($kbd) $svc->addAccessory($p4, $kbd, 1);
        $ready($p4);
        $svc->handover($p4, ['email' => 'budi.santoso@inventaris.test', 'condition' => 'good', 'signature_receiver' => $this->signature(), 'signature_it' => $this->signature()]);
        \App\Models\ArrivalPlan::whereKey($p4->id)->update(['arrival_date' => now()->subDays(5)->toDateString(), 'handed_over_at' => now()->subDays(5)]);

        // 5) Dibatalkan: pencadangan dilepas
        $p5 = $mk(['name' => 'Joko Widodo Putra', 'department_id' => $dept['Marketing']->id, 'position' => 'Sales', 'arrival_date' => now()->addDays(20)->toDateString()]);
        if ($a = $take()) $svc->addAsset($p5, $a);
        $svc->cancel($p5, 'Rencana penempatan dibatalkan oleh HRD.');
    }

    // ------------------------------------------------------------------ opname
    private function opnames($loc, User $staff): void
    {
        $this->closeOpname($loc['Kantor Pusat']->id, $staff, now()->subDays(12), 0.8);
        // sesi berjalan di Cabang Makassar
        $op = StockOpname::create(['location_id' => $loc['Cabang Makassar']->id, 'started_at' => now()->subHours(2), 'officer_id' => $staff->id, 'status' => 'running']);
        foreach (Asset::where('location_id', $loc['Cabang Makassar']->id)->take(2)->get() as $x) {
            StockOpnameItem::create(['stock_opname_id' => $op->id, 'asset_id' => $x->id, 'result' => 'found', 'condition' => $x->condition, 'scanned_at' => now()->subHour()]);
        }
    }

    private function closeOpname(int $locationId, User $staff, $when, float $ratio): void
    {
        $op = StockOpname::create(['location_id' => $locationId, 'started_at' => $when, 'officer_id' => $staff->id, 'status' => 'running']);
        $inLoc = Asset::where('location_id', $locationId)->active()->get();
        $seen = [];
        foreach ($inLoc->take((int) floor($inLoc->count() * $ratio)) as $x) {
            StockOpnameItem::create(['stock_opname_id' => $op->id, 'asset_id' => $x->id, 'result' => 'found', 'condition' => $x->condition, 'scanned_at' => $when->copy()->addMinutes(mt_rand(5, 50))]);
            $seen[] = $x->id;
        }
        $stray = Asset::where('location_id', '!=', $locationId)->whereNotNull('location_id')->where('status', 'deployed')->first();
        if ($stray) {
            StockOpnameItem::create(['stock_opname_id' => $op->id, 'asset_id' => $stray->id, 'result' => 'wrong_location', 'condition' => $stray->condition, 'notes' => 'Tercatat di lokasi lain', 'scanned_at' => $when->copy()->addMinutes(55)]);
            $seen[] = $stray->id;
        }
        foreach ($inLoc->whereNotIn('id', $seen) as $x) {
            StockOpnameItem::create(['stock_opname_id' => $op->id, 'asset_id' => $x->id, 'result' => 'missing', 'condition' => $x->condition]);
        }
        $op->update(['status' => 'finished', 'finished_at' => $when->copy()->addHour()]);
    }
}
