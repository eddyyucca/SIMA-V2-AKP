<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAsset extends CreateRecord
{
    protected static string $resource = AssetResource::class;

    /** Aset milik sendiri: kosongkan data sewa. Aset sewa: harga beli 0, tanggal beli = mulai sewa. */
    public static function normalizeOwnership(array $data): array
    {
        foreach (['useful_life_years', 'residual_percent'] as $k) {
            if (($data[$k] ?? '') === '') $data[$k] = null;      // kosong = ikut kategori
        }
        // nilai yang sama dengan kategori disimpan sebagai "ikut kategori" (kosong), agar perubahan umur di Pengaturan ikut berlaku
        if (! empty($data['category_id']) && ($c = \App\Models\Category::find($data['category_id']))) {
            if (($data['useful_life_years'] ?? null) !== null && (int) $data['useful_life_years'] === $c->effectiveLifeYears()) $data['useful_life_years'] = null;
            if (($data['residual_percent'] ?? null) !== null && (float) $data['residual_percent'] === (float) $c->residual_percent) $data['residual_percent'] = null;
        }
        // merek/model dikosongkan di form -> teksnya ikut kosong (bukan ditautkan ulang)
        if (empty($data['brand_id'])) { $data['brand'] = null; $data['brand_id'] = null; $data['asset_model_id'] = null; $data['model'] = null; }
        if (empty($data['asset_model_id'])) { $data['asset_model_id'] = null; $data['model'] = null; }
        if (($data['ownership'] ?? 'owned') === 'rental') {
            $data['purchase_price'] = 0;
            $data['purchase_date'] = $data['rental_start'] ?? null;
            $data['warranty_until'] = null;
        } else {
            foreach (['rental_contract_no', 'rental_start', 'rental_end'] as $k) $data[$k] = null;
            $data['rental_monthly_cost'] = 0;
            $data['rental_deposit'] = 0;
        }
        return $data;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'available';
        $data = static::normalizeOwnership($data);
        if (empty($data['serial_number'])) $data['serial_number'] = null;
        return $data;
    }
}
