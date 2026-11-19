<?php

namespace Tests\Qa;

use App\Filament\Resources\AssetAssignments\Pages\ManageAssetAssignments;
use App\Filament\Resources\Disposals\Pages\ManageDisposals;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Disposal;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa16_BastViewTest extends QaTestCase
{
    private function mine(): AssetAssignment
    {
        $andi = $this->emp('Andi Saputra');
        return AssetAssignment::where('to_employee_id', $andi->id)->orWhere('from_employee_id', $andi->id)->orderBy('id')->firstOrFail();
    }

    private function others(): AssetAssignment
    {
        $andi = $this->emp('Andi Saputra');
        return AssetAssignment::where('to_employee_id', '!=', $andi->id)->where(fn ($q) => $q->where('from_employee_id', '!=', $andi->id)->orWhereNull('from_employee_id'))->firstOrFail();
    }

    public function test_view_opens_the_pdf_inline_while_download_stays_an_attachment(): void
    {
        $this->as($this->staff());
        $a = AssetAssignment::whereNotNull('bast_pdf_path')->firstOrFail();

        $view = $this->get(route('bast.view', $a));
        $view->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline', $view->headers->get('content-disposition'));
        $this->assertStringContainsString(str_replace('/', '-', $a->bast_number) . '.pdf', $view->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $view->streamedContent());

        $dl = $this->get(route('bast.download', $a));
        $dl->assertOk();
        $this->assertStringContainsString('attachment', $dl->headers->get('content-disposition'));
        $this->assertSame($view->streamedContent(), $dl->streamedContent(), 'isi dokumen sama persis');
        $this->assertStringEndsWith('/lihat', route('bast.view', $a));
    }

    public function test_view_has_the_same_access_rules_as_download(): void
    {
        $own = $this->mine();
        $other = $this->others();
        $this->as($this->plain());
        $this->get(route('bast.view', $own))->assertOk();                                      // karyawan boleh melihat BAST miliknya
        $this->get(route('bast.view', $other))->assertForbidden();                             // bukan miliknya
        $this->as($this->staff())->get(route('bast.view', $other))->assertOk();
        $this->as($this->mgmt())->get(route('bast.view', $other))->assertOk();
        auth()->logout();
        $this->get(route('bast.view', $own))->assertRedirect();                                // tamu diarahkan login
    }

    public function test_missing_pdf_is_generated_on_demand_instead_of_404(): void
    {
        $this->as($this->staff());
        $a = AssetAssignment::whereNotNull('bast_pdf_path')->firstOrFail();
        $backup = \Storage::disk('local')->get($a->bast_pdf_path);              // file bersama seluruh tes: wajib dipulihkan
        \Storage::disk('local')->delete($a->bast_pdf_path);
        try {
            // berkas hilang -> dibuat ulang dari data serah terima, bukan 404
            $this->get(route('bast.view', $a))->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertTrue(\Storage::disk('local')->exists($a->fresh()->bast_pdf_path));
            \Storage::disk('local')->delete($a->fresh()->bast_pdf_path);
            $this->get(route('bast.download', $a))->assertOk();
        } finally {
            \Storage::disk('local')->put($a->bast_pdf_path, $backup);
        }
        $b = AssetAssignment::create(['asset_id' => Asset::first()->id, 'type' => 'checkin', 'bast_number' => 'BAST/TEST/0001', 'assigned_at' => now(), 'condition' => 'good']);
        // serah terima hasil impor SIMA tidak punya PDF: dibuat saat pertama dibuka
        $this->get(route('bast.view', $b))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertNotNull($b->fresh()->bast_pdf_path);
    }

    public function test_bast_list_shows_both_view_and_download_actions(): void
    {
        $this->as($this->staff());
        $a = AssetAssignment::whereNotNull('bast_pdf_path')->orderByDesc('assigned_at')->firstOrFail();
        Livewire::test(ManageAssetAssignments::class)
            ->assertActionVisible(TestAction::make('bast_view')->table($a))->assertActionVisible(TestAction::make('bast')->table($a))
            ->assertActionHasLabel(TestAction::make('bast_view')->table($a), 'Lihat')->assertActionHasLabel(TestAction::make('bast')->table($a), 'Unduh BAST')
            ->assertActionHasUrl(TestAction::make('bast_view')->table($a), route('bast.view', $a))
            ->assertActionHasUrl(TestAction::make('bast')->table($a), route('bast.download', $a));
        // serah terima tanpa PDF (hasil impor SIMA): tombol tetap ada, PDF dibuat saat dibuka
        $b = AssetAssignment::create(['asset_id' => Asset::first()->id, 'type' => 'checkin', 'bast_number' => 'BAST/TEST/0002', 'assigned_at' => now()->addDay(), 'condition' => 'good']);
        Livewire::test(ManageAssetAssignments::class)->assertActionVisible(TestAction::make('bast_view')->table($b))->assertActionVisible(TestAction::make('bast')->table($b));
    }

    public function test_asset_history_has_lihat_and_unduh_links_for_each_bast(): void
    {
        $this->as($this->staff());
        $a = Asset::whereHas('assignments')->orderBy('id')->firstOrFail();
        $row = $a->assignments()->whereNotNull('bast_pdf_path')->firstOrFail();
        $html = $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a]))->assertOk()->getContent();
        $this->assertStringContainsString($row->bast_number, $html);
        $this->assertStringContainsString('href="' . route('bast.view', $row) . '" target="_blank"', $html);      // Lihat: tab baru
        $this->assertStringContainsString('href="' . route('bast.download', $row) . '"', $html);                  // Unduh
        $this->assertStringContainsString('>Lihat</a>', $html);
        $this->assertStringContainsString('>Unduh</a>', $html);
        $this->assertGreaterThanOrEqual($a->assignments()->whereNotNull('bast_pdf_path')->count(), substr_count($html, '>Lihat</a>'));
    }

    public function test_disposal_report_can_also_be_viewed_inline(): void
    {
        $this->as($this->staff());
        $d = Disposal::where('status', 'approved')->whereNotNull('ba_pdf_path')->firstOrFail();
        $res = $this->get(route('disposal.ba.view', $d));
        $res->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline', $res->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $res->streamedContent());
        $this->assertStringContainsString('attachment', $this->get(route('disposal.ba', $d))->headers->get('content-disposition'));
        Livewire::test(ManageDisposals::class)
            ->assertActionVisible(TestAction::make('ba_view')->table($d))->assertActionHasUrl(TestAction::make('ba_view')->table($d), route('disposal.ba.view', $d))
            ->assertActionHasLabel(TestAction::make('ba')->table($d), 'Unduh BA');
        $this->as($this->plain())->get(route('disposal.ba.view', $d))->assertForbidden();
    }

    public function test_newly_created_bast_can_be_viewed_right_after_checkout(): void
    {
        $this->as($this->staff());
        $asset = $this->freeAsset();
        Livewire::test(\App\Filament\Resources\Assets\Pages\ViewAsset::class, ['record' => $asset->getKey()])
            ->callAction('checkout', ['employee_id' => $this->emp('Andi Saputra')->id, 'condition' => 'good'])->assertHasNoActionErrors();
        $row = $asset->assignments()->first();
        $this->get(route('bast.view', $row))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->as($this->plain())->get(route('bast.view', $row))->assertOk();                  // penerima langsung bisa melihat BAST-nya
    }
}
