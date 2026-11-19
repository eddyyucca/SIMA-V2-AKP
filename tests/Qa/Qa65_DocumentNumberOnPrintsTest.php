<?php

namespace Tests\Qa;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\BastNumberReservation;
use App\Models\Disposal;
use App\Services\AssetService;
use Tests\QaTestCase;

class Qa65_DocumentNumberOnPrintsTest extends QaTestCase
{
    public function test_disposal_ba_shows_a_stable_document_number(): void
    {
        $asset = Asset::firstOrFail();
        $d = Disposal::create(['asset_id' => $asset->id, 'requested_by' => $this->admin()->id, 'reason' => 'uji', 'data_wiped' => true,
            'method' => 'donate', 'status' => 'approved', 'approved_by' => $this->admin()->id, 'approved_at' => now()]);

        $no = $d->documentNumber();
        $this->assertMatchesRegularExpression('#^BA/HPS/\d{4}/\d{4}$#', $no);
        $this->assertSame($no, $d->fresh()->documentNumber());                                   // sama setiap dicetak ulang
        $html = view('pdf.disposal', ['d' => $d->load(['asset', 'requester', 'approver'])])->render();
        $this->assertStringContainsString('No. Dokumen: ' . $no, $html);
    }

    public function test_bast_shows_the_document_number_even_when_it_is_a_long_imported_one(): void
    {
        $asset = Asset::with('category')->firstOrFail();
        foreach (['IT/BAST/2026/11/0032', 'BAST-AKP_IT-00001-1787117684', 'BAST/SIMA-33'] as $no) {
            $a = new AssetAssignment(['asset_id' => $asset->id, 'type' => 'checkout', 'condition' => $asset->condition, 'assigned_at' => now(), 'bast_number' => $no]);
            $a->setRelation('asset', $asset); $a->setRelation('from', null); $a->setRelation('to', null); $a->setRelation('location', $asset->location); $a->setRelation('creator', null);
            $html = view('pdf.bast', ['a' => $a, 'sigReceiver' => null, 'sigIt' => null, 'form' => false])->render();
            $this->assertStringContainsString('No. Dokumen', $html);
            $this->assertStringContainsString(e($no), $html);
        }
    }

    public function test_printed_form_gets_an_automatic_number_that_the_recorded_handover_reuses(): void
    {
        $this->as($this->staff());
        $svc = app(AssetService::class);
        $asset = $this->freeAsset();

        $no = $svc->reserveBastNumber($asset, 'checkout');
        $this->assertMatchesRegularExpression('#^IT/BAST/\d{4}/\d{2}/\d{4}$#', $no);
        $this->assertSame($no, $svc->reserveBastNumber($asset, 'checkout'), 'cetak ulang memakai nomor yang sama');
        $this->assertStringStartsWith('%PDF', $svc->bastFormPdf($asset, 'checkout', null));
        $this->assertSame(1, BastNumberReservation::where('asset_id', $asset->id)->where('type', 'checkout')->count());

        // aset lain tidak memakai nomor yang sudah tercetak
        $other = $svc->reserveBastNumber(Asset::where('id', '!=', $asset->id)->firstOrFail(), 'checkout');
        $this->assertNotSame($no, $other);

        // serah terima dicatat -> nomor di kertas = nomor di sistem
        $a = $svc->checkout($asset, $this->emp('Andi Saputra'), ['condition' => 'good']);
        $this->assertSame($no, $a->bast_number);
        $this->assertNotNull(BastNumberReservation::where('bast_number', $no)->value('used_at'));

        // sesudahnya, cetak berikutnya mendapat nomor baru
        $this->assertNotSame($no, $svc->reserveBastNumber($asset->fresh(), 'checkout'));
    }
}
