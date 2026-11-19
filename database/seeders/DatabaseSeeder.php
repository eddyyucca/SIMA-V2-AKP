<?php

namespace Database\Seeders;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Department;
use App\Models\Employee;
use App\Models\License;
use App\Models\Location;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->call(RolesSeeder::class);

        // Password awal: ganti segera setelah login pertama.
        $pw = env('SEED_PASSWORD', 'Inventaris#2026');
        foreach ([
            ['Administrator', 'admin@inventaris.test', 'Admin'],
            ['Staff IT', 'staff@inventaris.test', 'Staff IT'],
            ['Karyawan Demo', 'user@inventaris.test', 'User'],
        ] as [$name, $email, $role]) {
            $u = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => $pw]);
            $u->syncRoles([$role]);
        }

        if (Category::exists()) return;

        $cats = [];
        foreach ([['Laptop', 'asset', 'NB'], ['Monitor', 'asset', 'MNT'], ['PC Desktop', 'asset', 'PC'], ['Printer', 'asset', 'PRT'], ['Perangkat Jaringan', 'asset', 'NET'],
            ['Aksesoris', 'accessory', null], ['Tinta & Toner', 'consumable', null], ['Sparepart', 'component', null]] as [$n, $t, $p]) {
            $cats[$n] = Category::create(['name' => $n, 'type' => $t, 'code_prefix' => $p]);
        }
        $dept = collect(['IT', 'Finance', 'HRD', 'Operasional'])->mapWithKeys(fn ($n) => [$n => Department::create(['name' => $n])]);
        $loc = Location::create(['name' => 'Kantor Pusat', 'branch' => 'Pusat', 'address' => '-']);
        Location::create(['name' => 'Gudang IT', 'branch' => 'Pusat']);
        $ven = Vendor::create(['name' => 'PT Sumber Teknologi', 'contact_person' => 'Budi', 'phone' => '0811000000', 'email' => 'sales@sumbertek.test']);

        $emps = [];
        foreach ([['Andi Saputra', 'andi@inventaris.test', 'IT'], ['Siti Rahma', 'siti@inventaris.test', 'Finance'], ['Rudi Hartono', 'rudi@inventaris.test', 'HRD']] as [$n, $e, $d]) {
            $emps[] = Employee::create(['nik' => (string) (2024000 + count($emps) + 1), 'name' => $n, 'email' => $e, 'department_id' => $dept[$d]->id, 'position' => 'Staff', 'status' => 'active']);
        }
        Employee::where('email', 'andi@inventaris.test')->update(['user_id' => User::where('email', 'user@inventaris.test')->value('id')]);

        foreach ([['Laptop', 'Lenovo', 'ThinkPad E14', 'SN-LNV-0001'], ['Laptop', 'HP', 'ProBook 440', 'SN-HP-0002'], ['Monitor', 'Dell', 'P2422H', 'SN-DL-0003'], ['Printer', 'HP', 'LaserJet 107a', 'SN-HPP-0004']] as [$c, $b, $m, $sn]) {
            Asset::create([
                'asset_tag' => Asset::generateTag($cats[$c]), 'category_id' => $cats[$c]->id, 'brand' => $b, 'model' => $m,
                'serial_number' => $sn, 'purchase_date' => now()->subMonths(6), 'vendor_id' => $ven->id, 'purchase_price' => 9500000,
                'warranty_until' => now()->addMonths(18), 'location_id' => $loc->id, 'status' => 'available', 'condition' => 'good',
            ]);
        }
        Accessory::create(['name' => 'Mouse Wireless Logitech', 'category_id' => $cats['Aksesoris']->id, 'qty_total' => 10, 'qty_available' => 10, 'min_qty' => 3, 'location_id' => $loc->id, 'unit_price' => 150000, 'vendor_id' => $ven->id]);
        Consumable::create(['name' => 'Toner HP 85A', 'category_id' => $cats['Tinta & Toner']->id, 'unit' => 'pcs', 'compatible_with' => 'HP LaserJet P1102, M1132, 107a', 'qty' => 5, 'min_qty' => 2, 'location_id' => $loc->id, 'unit_price' => 850000, 'vendor_id' => $ven->id]);
        Component::create(['name' => 'RAM DDR4 8GB', 'category_id' => $cats['Sparepart']->id, 'specs' => 'DDR4 3200MHz SODIMM', 'qty_total' => 6, 'qty_available' => 6, 'min_qty' => 2, 'location_id' => $loc->id, 'price' => 450000, 'vendor_id' => $ven->id]);
        License::create(['name' => 'Microsoft 365 Business', 'type' => 'subscription', 'license_key' => 'DEMO-KEY', 'seats' => 5, 'purchase_date' => now()->subMonths(2), 'expires_at' => now()->addDays(25), 'price' => 12000000, 'vendor_id' => $ven->id]);
    }
}
