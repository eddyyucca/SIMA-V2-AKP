<?php

namespace Tests\Qa;

use App\Models\Asset;
use App\Models\AssetTagAlias;
use App\Models\Category;
use App\Models\Employee;
use App\Services\LabelService;
use Tests\QaTestCase;

/** PostgreSQL membedakan huruf pada "="; SIMA (MariaDB) tidak. Pemindaian dan pencarian persis harus tetap menemukan data meski huruf berbeda. */
class Qa67_CaseInsensitiveLookupTest extends QaTestCase
{
    public function test_scanning_finds_assets_regardless_of_letter_case(): void
    {
        $a = Asset::whereNotNull('serial_number')->firstOrFail();
        foreach ([$a->asset_tag, strtolower($a->asset_tag), strtoupper($a->asset_tag), '  ' . strtolower($a->asset_tag) . ' ',
                  'https://x.test/admin/scan?kode=' . rawurlencode(strtolower($a->asset_tag)), $a->serial_number, strtolower($a->serial_number), strtoupper($a->serial_number)] as $code) {
            $this->assertSame($a->id, Asset::findByCode($code)?->id, "kode '{$code}' harus menemukan aset");
            $this->assertSame(['asset', $a->id], [LabelService::resolve($code)[0] ?? null, LabelService::resolve($code)[1]->id ?? null]);
        }
        $this->assertNull(Asset::findByCode('TIDAK-ADA-0000'));
    }

    public function test_legacy_tag_aliases_are_found_regardless_of_letter_case(): void
    {
        $a = Asset::firstOrFail();
        AssetTagAlias::create(['asset_id' => $a->id, 'tag' => 'LAP-006']);
        foreach (['LAP-006', 'lap-006', 'Lap-006'] as $code) $this->assertSame($a->id, Asset::findByCode($code)?->id, $code);
    }

    public function test_where_ci_matches_exactly_but_ignores_letter_case(): void
    {
        $e = Employee::whereNotNull('email')->firstOrFail();
        $this->assertTrue(Employee::whereCi('email', strtoupper($e->email))->whereKey($e->id)->exists());
        $this->assertTrue(Employee::whereCi('email', '  ' . $e->email . ' ')->whereKey($e->id)->exists());
        $this->assertFalse(Employee::whereCi('email', substr($e->email, 0, -1))->whereKey($e->id)->exists(), 'tetap pencocokan persis, bukan LIKE');

        $c = Category::firstOrFail();
        $this->assertTrue(Category::whereCi('name', mb_strtolower($c->name))->whereKey($c->id)->exists());
        $this->assertTrue(Category::where('type', $c->type)->whereCi('name', mb_strtoupper($c->name))->whereKey($c->id)->exists(), 'bisa dirangkai dengan kondisi lain');
    }
}
