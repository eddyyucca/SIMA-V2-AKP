<?php

namespace Tests\Qa;

use App\Filament\Pages\OpnameSession;
use App\Filament\Pages\ScanPage;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Setting;
use App\Models\StockOpname;
use App\Services\LabelService;
use App\Support\AssetTagMigrator;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa15_AssetTagFormatTest extends QaTestCase
{
    private function cat(string $code): Category { return Category::where('code_prefix', $code)->firstOrFail(); }

    public function test_new_tags_use_awalan_kategori_urut_without_year(): void
    {
        $tag = Asset::generateTag($this->cat('NB'));
        $this->assertMatchesRegularExpression('/^AKP-IT-NB-\d{4}$/', $tag);
        $this->assertStringNotContainsString((string) now()->year, $tag);
        $this->assertMatchesRegularExpression('/^AKP-IT-MNT-\d{4}$/', Asset::generateTag($this->cat('MNT')));
        $this->assertSame('AKP-IT', Asset::tagPrefix());
    }

    public function test_sequence_is_per_category_continuous_and_counts_deleted_assets(): void
    {
        $cat = $this->cat('NET');
        $max = Asset::withTrashed()->where('asset_tag', 'like', 'AKP-IT-NET-%')->count();
        $this->assertSame('AKP-IT-NET-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT), Asset::generateTag($cat));

        $a = Asset::create(['asset_tag' => Asset::generateTag($cat), 'category_id' => $cat->id, 'status' => 'available', 'condition' => 'good']);
        $b = Asset::create(['asset_tag' => Asset::generateTag($cat), 'category_id' => $cat->id, 'status' => 'available', 'condition' => 'good']);
        $this->assertSame((int) substr($a->asset_tag, -4) + 1, (int) substr($b->asset_tag, -4));
        $b->delete();                                                                   // soft delete: nomor tidak dipakai ulang
        $this->assertSame((int) substr($b->asset_tag, -4) + 1, (int) substr(Asset::generateTag($cat), -4));
        // kategori lain tidak terpengaruh
        $this->assertSame(Asset::withTrashed()->where('asset_tag', 'like', 'AKP-IT-MNT-%')->count() + 1, (int) substr(Asset::generateTag($this->cat('MNT')), -4));
    }

    public function test_numbering_continues_past_999_and_ignores_the_year(): void
    {
        $cat = $this->cat('UPS');
        Asset::create(['asset_tag' => 'AKP-IT-UPS-999', 'category_id' => $cat->id, 'status' => 'available', 'condition' => 'good']);
        $this->assertSame('AKP-IT-UPS-1000', Asset::generateTag($cat));
        Asset::create(['asset_tag' => 'AKP-IT-UPS-9999', 'category_id' => $cat->id, 'status' => 'available', 'condition' => 'good']);
        $this->assertSame('AKP-IT-UPS-10000', Asset::generateTag($cat));                   // lewat 4 digit tetap bertambah normal
        Asset::where('asset_tag', 'AKP-IT-UPS-9999')->forceDelete();
        $this->travelTo(now()->addYears(2));
        $this->assertSame('AKP-IT-UPS-1000', Asset::generateTag($cat));                    // ganti tahun tidak mengulang urutan
        $this->travelBack();
    }

    public function test_category_without_prefix_uses_ast(): void
    {
        $c = Category::create(['name' => 'Tanpa Prefix', 'type' => 'asset']);
        $this->assertSame('AKP-IT-AST-0001', Asset::generateTag($c));
    }

    public function test_demo_data_tags_follow_the_new_format_and_are_contiguous(): void
    {
        $byCat = Asset::withTrashed()->get()->groupBy(fn ($a) => preg_replace('/-\d+$/', '', $a->asset_tag));
        foreach (Asset::withTrashed()->get() as $a) {
            if (str_starts_with($a->asset_tag, 'DEP-')) continue;
            $this->assertMatchesRegularExpression('/^AKP-IT-[A-Z]+-\d{4,}$/', $a->asset_tag);
        }
        foreach ($byCat as $head => $rows) {
            $nums = $rows->map(fn ($a) => (int) substr($a->asset_tag, strrpos($a->asset_tag, '-') + 1))->sort()->values();
            $this->assertSame(range(1, $nums->count()), $nums->all(), "{$head}: nomor urut harus 001..N tanpa celah");
        }
        $this->assertSame(Asset::withTrashed()->count(), Asset::withTrashed()->distinct('asset_tag')->count('asset_tag'));
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_create_form_fills_the_new_tag_and_tag_must_be_unique(): void
    {
        $this->as($this->staff());
        $cat = $this->cat('NB');
        $tag = Asset::generateTag($cat);
        Livewire::test(CreateAsset::class)->fillForm(['category_id' => $cat->id, 'asset_tag' => $tag, 'condition' => 'good'])->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateAsset::class)->fillForm(['category_id' => $cat->id, 'asset_tag' => $tag, 'condition' => 'good'])->call('create')->assertHasFormErrors(['asset_tag']);
        $this->assertNotSame($tag, Asset::generateTag($cat));                                // nomor berikutnya maju
        $t = Livewire::test(CreateAsset::class)->set('data.category_id', $cat->id);
        $this->assertSame(Asset::generateTag($cat), $t->get('data.asset_tag'));
    }

    // ---------------------------------------------------------------- pengaturan awalan
    public function test_prefix_setting_changes_only_new_tags_and_is_admin_only(): void
    {
        $this->as($this->staff())->get('/admin/pengaturan')->assertForbidden();
        $this->as($this->mgmt())->get('/admin/pengaturan')->assertForbidden();
        $this->as($this->plain())->get('/admin/pengaturan')->assertForbidden();
        $this->as($this->admin());
        $this->get('/admin/pengaturan')->assertOk()->assertSee('Awalan kode aset')->assertSee('AKP-IT-NB-0001');

        $old = Asset::where('asset_tag', 'like', 'AKP-IT-NB-%')->pluck('asset_tag')->all();
        Livewire::test(Settings::class)->assertSet('prefix', 'AKP-IT')->set('prefix', 'akp-fin')->call('save')->assertHasNoErrors()->assertNotified('Pengaturan disimpan');
        $this->assertSame('AKP-FIN', Setting::get('asset_tag_prefix'));
        $this->assertSame('AKP-FIN-NB-0001', Asset::generateTag($this->cat('NB')));        // awalan baru mulai dari 001
        $this->assertSame($old, Asset::where('asset_tag', 'like', 'AKP-IT-NB-%')->pluck('asset_tag')->all(), 'kode yang sudah ada tidak berubah');
        $this->assertNotNull(\Spatie\Activitylog\Models\Activity::where('subject_type', Setting::class)->latest('id')->first(), 'perubahan pengaturan tercatat');
        // contoh kode di halaman mengikuti ketikan
        $this->assertSame('XYZ-9-NB-0001', Livewire::test(Settings::class)->set('prefix', 'xyz-9')->instance()->example());
        $this->assertSame('—', Livewire::test(Settings::class)->set('prefix', 'tidak valid!')->instance()->example());
    }

    public function test_prefix_setting_validation(): void
    {
        $this->as($this->admin());
        foreach (['', 'AB CD', 'AKP_IT', '-AKP', 'AKP-', 'AKP--IT', str_repeat('A', 21), 'AKP/IT'] as $bad) {
            Livewire::test(Settings::class)->set('prefix', $bad)->call('save')->assertHasErrors(['prefix']);
        }
        $this->assertSame('AKP-IT', Asset::tagPrefix(), 'isian salah tidak menyimpan apa pun');
        Livewire::test(Settings::class)->set('prefix', 'IT2')->call('save')->assertHasNoErrors();
        $this->assertSame('IT2-NB-0001', substr(Asset::generateTag($this->cat('NB')), 0, 11));
    }

    // ---------------------------------------------------------------- kode lama
    public function test_renumbering_old_tags_keeps_legacy_codes_and_is_idempotent(): void
    {
        $lpt = Asset::where('asset_tag', 'like', 'AKP-IT-NB-%')->orderBy('id')->take(3)->get();
        $old = ['LPT-2025-007', 'LPT-2026-001', 'LPT-2026-002'];
        foreach ($lpt as $i => $a) DB::table('assets')->where('id', $a->id)->update(['asset_tag' => $old[$i]]);

        $changed = AssetTagMigrator::renumber('AKP-IT');
        $this->assertGreaterThanOrEqual(3, $changed);
        $now = Asset::whereIn('id', $lpt->pluck('id'))->orderBy('id')->get();
        foreach ($now as $i => $a) $this->assertSame($a->id, Asset::findByCode($old[$i])?->id);   // kode lama tersimpan di riwayat
        foreach ($now as $a) $this->assertMatchesRegularExpression('/^AKP-IT-NB-\d{4}$/', $a->asset_tag);
        $this->assertCount(3, $now->pluck('asset_tag')->unique());
        $this->assertSame(0, AssetTagMigrator::renumber('AKP-IT'));                         // dijalankan ulang: tidak ada yang berubah
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('assets', 'legacy_tag') && Asset::whereNotNull('legacy_tag')->exists(), 'kolom kode lama tidak diisi lagi');
        $log = \Spatie\Activitylog\Models\Activity::where('subject_type', Asset::class)->where('subject_id', $lpt[0]->id)->latest('id')->first();
        $this->assertSame('LPT-2025-007', $log->properties['old']['asset_tag'] ?? $log->attribute_changes['old']['asset_tag'] ?? null);   // riwayat audit
        $this->artisan('inventory:audit')->assertSuccessful();
    }

    public function test_renumbering_continues_after_tags_that_are_already_new_and_handles_trashed(): void
    {
        $cat = $this->cat('TAB');
        $existing = Asset::withTrashed()->where('asset_tag', 'like', 'AKP-IT-TAB-%')->count();
        $legacy = Asset::create(['asset_tag' => 'TAB-2024-009', 'category_id' => $cat->id, 'status' => 'available', 'condition' => 'good']);
        $gone = Asset::create(['asset_tag' => 'TAB-2024-010', 'category_id' => $cat->id, 'status' => 'available', 'condition' => 'good']);
        $gone->delete();
        $this->assertSame(2, AssetTagMigrator::renumber('AKP-IT'));
        $this->assertSame('AKP-IT-TAB-' . str_pad((string) ($existing + 1), 4, '0', STR_PAD_LEFT), $legacy->fresh()->asset_tag);
        $this->assertSame('AKP-IT-TAB-' . str_pad((string) ($existing + 2), 4, '0', STR_PAD_LEFT), Asset::withTrashed()->find($gone->id)->asset_tag);
    }

    public function test_old_printed_labels_still_scan_and_search(): void
    {
        $this->as($this->staff());
        $a = Asset::where('asset_tag', 'like', 'AKP-IT-NB-%')->orderBy('id')->firstOrFail();
        \App\Models\AssetTagAlias::create(['asset_id' => $a->id, 'tag' => 'LPT-2026-001']);

        $this->assertSame($a->id, LabelService::resolve('LPT-2026-001')[1]->id);            // kode lama dikenali
        $this->assertSame($a->id, LabelService::resolve($a->asset_tag)[1]->id);             // dan kode baru
        $this->assertSame($a->asset_tag, Livewire::test(ScanPage::class)->call('onScan', 'LPT-2026-001')->assertNoRedirect()->get('card')['tag']);

        $op = StockOpname::create(['location_id' => $a->location_id ?? \App\Models\Location::first()->id, 'started_at' => now(), 'officer_id' => auth()->id(), 'status' => 'running']);
        Livewire::withQueryParams(['opname' => $op->id])->test(OpnameSession::class)->call('onScan', 'LPT-2026-001');
        $this->assertTrue($op->items()->where('asset_id', $a->id)->exists(), 'opname mengenali kode lama');

        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]))->assertOk()->assertDontSee('Kode lama');
        $this->get('/admin/assets')->assertOk();
    }

    public function test_label_with_the_longer_tag_still_fits_on_24mm_and_9mm(): void
    {
        $this->as($this->staff());
        $a = Asset::where('asset_tag', 'like', 'AKP-IT-NB-%')->firstOrFail();
        $G = LabelService::frame(24, 73);
        $fit = LabelService::fit($a->asset_tag, $G['textW'], $G['h2'] * 2.45);
        $this->assertGreaterThanOrEqual(7.0, $fit, 'kode aset tetap terbaca besar');
        $this->assertSame(strtoupper($a->asset_tag), LabelService::truncateToFit(strtoupper($a->asset_tag), $G['textW']), 'kode tidak dipotong');
        $this->get(route('labels.print', ['kind' => 'asset', 'ids' => (string) $a->id]))->assertOk();
        $this->get(route('labels.print', ['kind' => 'asset', 'ids' => (string) $a->id, 'tape' => 12, 'length' => 45]))->assertOk();
    }
}
