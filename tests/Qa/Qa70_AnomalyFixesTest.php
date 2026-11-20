<?php

namespace Tests\Qa;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Repair;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\QaTestCase;

/** Perbaikan anomali: PDF BAST hilang, aset Perbaikan tanpa tiket, saldo awal impor tidak dianggap "pembelian hilang". */
class Qa70_AnomalyFixesTest extends QaTestCase
{
    public function test_generate_missing_creates_pdfs_only_where_absent_and_never_overwrites(): void
    {
        $a = AssetAssignment::firstOrFail();
        DB::table('asset_assignments')->where('id', $a->id)->update(['bast_pdf_path' => null]);
        $untouched = AssetAssignment::where('id', '!=', $a->id)->whereNotNull('bast_pdf_path')->first();

        $this->artisan('bast:generate-missing', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($a->fresh()->bast_pdf_path, 'simulasi tidak membuat apa pun');

        $this->artisan('bast:generate-missing')->assertSuccessful();
        $path = $a->fresh()->bast_pdf_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($path));

        if ($untouched) {
            $before = Storage::disk('local')->get($untouched->bast_pdf_path);
            $this->artisan('bast:generate-missing')->assertSuccessful();
            $this->assertSame($before, Storage::disk('local')->get($untouched->fresh()->bast_pdf_path), 'berkas yang ada tidak ditimpa');
        }
    }

    public function test_assets_in_repair_status_without_a_ticket_get_one_and_nothing_else_changes(): void
    {
        $asset = Asset::where('status', 'available')->firstOrFail();
        $asset->update(['status' => 'repair']);
        $this->assertFalse(Repair::where('asset_id', $asset->id)->where('status', '!=', 'done')->exists());

        $this->artisan('inventory:fix-anomalies')->assertSuccessful();                       // tanpa --apply: tidak mengubah
        $this->assertFalse(Repair::where('asset_id', $asset->id)->exists());

        $this->artisan('inventory:fix-anomalies', ['--apply' => true])->assertSuccessful();
        $r = Repair::where('asset_id', $asset->id)->first();
        $this->assertNotNull($r);
        $this->assertSame('in_progress', $r->status);
        $this->assertSame('repair', $asset->fresh()->status, 'status aset tidak diubah');

        $count = Repair::count();
        $this->artisan('inventory:fix-anomalies', ['--apply' => true])->assertSuccessful();
        $this->assertSame($count, Repair::count(), 'idempoten');
    }

    public function test_import_opening_balances_are_not_reported_as_missing_purchases(): void
    {
        $acc = \App\Models\Accessory::firstOrFail();
        $m = DB::table('stock_movements')->insertGetId(['stockable_type' => \App\Models\Accessory::class, 'stockable_id' => $acc->id, 'type' => 'in', 'qty' => 5,
            'reason' => 'Saldo awal (impor SIMA)', 'moved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $out = $this->artisanOutput('inventory:audit');
        $this->assertStringNotContainsString("pergerakan masuk #{$m} tidak punya tepat satu catatan pembelian", $out);
    }

    private function artisanOutput(string $cmd): string
    {
        \Illuminate\Support\Facades\Artisan::call($cmd);
        return \Illuminate\Support\Facades\Artisan::output();
    }
}
