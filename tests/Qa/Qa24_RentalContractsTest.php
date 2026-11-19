<?php

namespace Tests\Qa;

use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\RentalAssets\Pages\ListRentalAssets;
use App\Models\Asset;
use App\Models\RentalContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\QaTestCase;

class Qa24_RentalContractsTest extends QaTestCase
{
    private function pdf(string $name = 'k.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%fake\n");
    }

    private function rental(): Asset
    {
        return Asset::where('ownership', 'rental')->where('status', '!=', 'returned')->orderBy('id')->firstOrFail();
    }

    public function test_upload_contract_one_renewal_and_contract_two_keep_history(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $a = $this->rental();

        Livewire::test(ViewAsset::class, ['record' => $a->id])->callAction('contract_upload', [
            'label' => 'Kontrak Utama', 'update_rental_end' => true, 'kind' => 'initial', 'contract_no' => 'KTR-A', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'file' => $this->pdf(),
        ])->assertHasNoActionErrors();
        Livewire::test(ViewAsset::class, ['record' => $a->id])->callAction('contract_upload', [
            'label' => 'kontrak  utama', 'update_rental_end' => true, 'kind' => 'renewal', 'contract_no' => 'KTR-A', 'start_date' => '2026-01-01', 'end_date' => '2030-12-31', 'file' => $this->pdf('r.pdf'),
        ])->assertHasNoActionErrors();
        Livewire::test(ViewAsset::class, ['record' => $a->id])->callAction('contract_upload', [
            'label' => 'Addendum', 'kind' => 'initial', 'contract_no' => 'KTR-B', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'file' => $this->pdf('b.pdf'),
        ])->assertHasNoActionErrors();

        $a->refresh()->load('rentalContracts');
        $this->assertCount(3, $a->rentalContracts);                                   // riwayat tidak menimpa
        $cur = $a->currentContracts();
        $this->assertCount(2, $cur);                                                   // "Kontrak Utama" & "kontrak  utama" = satu jenis
        $this->assertCount(2, $cur['kontrak utama']);
        $this->assertSame('renewal', $cur['kontrak utama'][0]->kind);
        $this->assertSame('Masa Aktif', $cur['kontrak utama'][0]->statusLabel());
        $this->assertSame('kontrak utama', $cur['kontrak utama'][0]->label);          // spasi ganda dirapikan
        $this->assertSame('Expired', $cur['addendum'][0]->statusLabel());               // 2024 sudah lewat
        $this->assertSame('2030-12-31', $a->rental_end->toDateString());               // kontrak 1 memajukan akhir sewa
        $this->assertSame('KTR-A', $a->rental_contract_no);
    }

    public function test_more_than_two_contract_types_are_allowed_and_label_required(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $a = $this->rental();
        foreach (['Kontrak Utama', 'Addendum', 'SLA', 'Berita Acara'] as $i => $l) {
            Livewire::test(ViewAsset::class, ['record' => $a->id])->callAction('contract_upload', [
                'label' => $l, 'kind' => 'initial', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'file' => $this->pdf("f$i.pdf"),
            ])->assertHasNoActionErrors();
        }
        $this->assertCount(4, $a->refresh()->load('rentalContracts')->currentContracts());
        Livewire::test(ViewAsset::class, ['record' => $a->id])->callAction('contract_upload', [
            'label' => '', 'kind' => 'initial', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'file' => $this->pdf(),
        ])->assertHasActionErrors(['label' => 'required']);
        $this->get(\App\Filament\Resources\RentalAssets\RentalAssetResource::getUrl('view', ['record' => $a]))->assertOk()->assertSee('SLA')->assertSee('Berita Acara');
    }

    public function test_download_uses_original_uploaded_file_name(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $a = $this->rental();
        Livewire::test(ViewAsset::class, ['record' => $a->id])->callAction('contract_upload', [
            'label' => 'Kontrak Utama', 'kind' => 'initial', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'file' => $this->pdf('Kontrak Sewa Laptop PT Maju.pdf'),
        ])->assertHasNoActionErrors();
        $c = RentalContract::latest('id')->firstOrFail();
        $this->assertSame('Kontrak Sewa Laptop PT Maju.pdf', $c->original_name);
        $this->assertSame('Kontrak Sewa Laptop PT Maju.pdf', $c->downloadName());
        $this->assertStringContainsString('Kontrak Sewa Laptop PT Maju.pdf', $this->as($this->staff())->get(route('contract.download', $c))->headers->get('content-disposition'));
    }

    public function test_contract_must_be_a_real_pdf_and_dates_ordered(): void
    {
        Storage::fake('local');
        $this->as($this->staff());
        $a = $this->rental();
        Livewire::test(ViewAsset::class, ['record' => $a->id])->callAction('contract_upload', [
            'label' => 'Kontrak Utama', 'kind' => 'initial', 'start_date' => '2026-06-01', 'end_date' => '2026-01-01', 'file' => $this->pdf(),
        ])->assertHasActionErrors(['end_date']);
        Livewire::test(ViewAsset::class, ['record' => $a->id])->callAction('contract_upload', [
            'label' => 'Kontrak Utama', 'kind' => 'initial', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->assertHasActionErrors(['file' => 'required']);
        $this->assertSame(0, RentalContract::count());
    }

    public function test_upload_action_hidden_for_non_rental_and_for_view_only_roles(): void
    {
        $owned = Asset::where('ownership', 'owned')->firstOrFail();
        $this->as($this->staff());
        Livewire::test(ViewAsset::class, ['record' => $owned->id])->assertActionHidden('contract_upload');
        $this->as($this->mgmt());
        Livewire::test(ViewAsset::class, ['record' => $this->rental()->id])->assertActionHidden('contract_upload');
    }

    public function test_contract_download_is_permission_guarded(): void
    {
        Storage::fake('local');
        $a = $this->rental();
        Storage::disk('local')->put('rental-contracts/x.pdf', "%PDF-1.4\n");
        $c = RentalContract::create(['asset_id' => $a->id, 'label' => 'Kontrak Utama', 'kind' => 'initial', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'path' => 'rental-contracts/x.pdf']);
        $this->as($this->plain())->get(route('contract.view', $c))->assertForbidden();
        $this->as($this->mgmt())->get(route('contract.view', $c))->assertOk();
        $this->as($this->staff())->get(route('contract.download', $c))->assertOk();
        auth()->forgetGuards();
        $this->get(route('contract.view', $c))->assertRedirect();                      // belum login
    }

    public function test_status_column_and_filter_in_rental_list(): void
    {
        $this->as($this->staff());
        $exp = Asset::where('ownership', 'rental')->where('status', '!=', 'returned')->whereDate('rental_end', '<', now()->toDateString())->get();
        $act = Asset::where('ownership', 'rental')->where('status', '!=', 'returned')->whereDate('rental_end', '>=', now()->toDateString())->get();
        $this->assertSame('Expired', $exp->first()->rental_contract_status);
        $this->assertSame('Masa Aktif', $act->first()->rental_contract_status);
        Livewire::test(ListRentalAssets::class)->filterTable('contract_status', 'expired')->assertCountTableRecords($exp->count());
        Livewire::test(ListRentalAssets::class)->filterTable('contract_status', 'active')->assertCountTableRecords($act->count());
    }

    public function test_rental_list_opens_detail_and_edit_inside_rental_menu(): void
    {
        $this->as($this->staff());
        $a = $this->rental();
        $view = \App\Filament\Resources\RentalAssets\RentalAssetResource::getUrl('view', ['record' => $a]);
        $edit = \App\Filament\Resources\RentalAssets\RentalAssetResource::getUrl('edit', ['record' => $a]);
        $this->assertStringContainsString('/admin/aset-sewa/' . $a->id, $view);
        $this->get($view)->assertOk()->assertSee($a->asset_tag);
        $this->get($edit)->assertOk();
        Livewire::test(ListRentalAssets::class)->assertTableActionHasUrl('view', $view, $a)->assertTableActionHasUrl('edit', $edit, $a);
        $owned = Asset::where('ownership', 'owned')->firstOrFail();
        $this->get('/admin/aset-sewa/' . $owned->id)->assertNotFound();                // aset milik sendiri tidak bocor ke menu sewa
    }
}
