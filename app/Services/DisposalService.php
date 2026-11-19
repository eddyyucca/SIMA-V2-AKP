<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Disposal;
use App\Models\LicenseSeat;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class DisposalService
{
    public function request(int $assetId, string $reason, bool $wiped): Disposal
    {
        if (! $wiped) throw new InvalidArgumentException('Wipe data wajib sebelum pengajuan disposal.');
        $asset = Asset::findOrFail($assetId);
        if (in_array($asset->status, Asset::INACTIVE, true)) throw new InvalidArgumentException('Aset sudah disposed / dikembalikan ke vendor.');
        if ($asset->ownership === 'rental') throw new InvalidArgumentException('Aset sewa tidak di-disposal: gunakan "Kembalikan ke Vendor".');
        if (\App\Models\ArrivalPlanItem::where('asset_id', $asset->id)->whereIn('status', \App\Models\ArrivalPlanItem::RESERVING)->exists()) {
            throw new InvalidArgumentException('Aset sedang dicadangkan untuk rencana kedatangan karyawan; lepaskan dari rencana dulu.');
        }
        if (Disposal::where('asset_id', $assetId)->where('status', 'pending')->exists()) {
            throw new InvalidArgumentException('Sudah ada pengajuan disposal yang menunggu untuk aset ini.');
        }
        $d = Disposal::create(['asset_id' => $assetId, 'requested_by' => auth()->id(), 'reason' => $reason, 'data_wiped' => true]);
        app(NotificationService::class)->notify(['Admin'], 'Pengajuan disposal menunggu approval', $asset->asset_tag . ': ' . $reason);
        return $d->fresh();
    }

    public function approve(Disposal $disposal, string $method, ?float $salePrice = null): void
    {
        if ($salePrice !== null && $salePrice < 0) throw new InvalidArgumentException('Harga jual tidak boleh negatif.');
        DB::transaction(function () use ($disposal, $method, $salePrice) {
            $d = Disposal::query()->lockForUpdate()->findOrFail($disposal->id);
            if ($d->status !== 'pending') throw new InvalidArgumentException('Pengajuan sudah diproses.');
            if (! $d->data_wiped) throw new InvalidArgumentException('Checklist wipe data belum dicentang.');
            $asset = Asset::query()->lockForUpdate()->findOrFail($d->asset_id);

            $d->update(['status' => 'approved', 'method' => $method, 'approved_by' => auth()->id(), 'approved_at' => now(),
                'sale_price' => in_array($method, ['sell', 'auction'], true) ? $salePrice : null]);
            $asset->update(['status' => 'disposed', 'employee_id' => null, 'retired_at' => now()->toDateString()]);   // penyusutan berhenti
            LicenseSeat::where('asset_id', $asset->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

            $d->load(['asset', 'requester', 'approver']);
            $path = 'disposal/BA-' . $d->id . '.pdf';
            Storage::disk('local')->put($path, Pdf::loadView('pdf.disposal', ['d' => $d])->setPaper('a4')->output());
            $d->update(['ba_pdf_path' => $path]);
        });
    }

    public function reject(Disposal $disposal): void
    {
        if ($disposal->status !== 'pending') throw new InvalidArgumentException('Pengajuan sudah diproses.');
        $disposal->update(['status' => 'rejected', 'approved_by' => auth()->id(), 'approved_at' => now()]);
    }
}
