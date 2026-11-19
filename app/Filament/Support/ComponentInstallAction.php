<?php

namespace App\Filament\Support;

use App\Models\Asset;
use App\Models\Component;
use App\Models\Employee;
use App\Models\ITRequest;
use App\Services\AccessoryHandoverService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Pasang / lepas komponen pada aset (mis. user minta upgrade RAM): satu form untuk semua pintu masuk (menu Komponen, tombol header
 * Komponen, halaman Aset). Hasilnya dokumen "Bukti Pemasangan Komponen Aset" yang bisa dicetak, ditandatangani, dan diarsipkan.
 * Aset yang belum punya pemegang (stok gudang IT) juga bisa dipasangi komponen.
 */
class ComponentInstallAction
{
    private static function canManage(): bool { return auth()->user()?->can('manage inventory') ?? false; }

    /** Aset yang bisa dimodifikasi, dengan pemegang saat ini pada labelnya. */
    public static function assetOptions(): array
    {
        return Asset::active()->where('status', '!=', 'lost')->with('employee')->orderBy('asset_tag')->get()->mapWithKeys(fn (Asset $a) => [
            $a->id => "{$a->asset_tag} — {$a->brand} {$a->model} · " . ($a->employee ? 'dipakai ' . $a->employee->name : 'belum ada pemegang (' . (Asset::STATUSES[$a->status] ?? $a->status) . ')'),
        ])->all();
    }

    public static function componentOptions(): array
    {
        return Component::where('qty_available', '>', 0)->orderBy('name')->get()
            ->mapWithKeys(fn (Component $c) => [$c->id => $c->code . ' — ' . $c->name . ' (tersedia ' . $c->qty_available . ')'])->all();
    }

    /** Ringkasan aset terpilih: pemegang dan komponen yang sedang terpasang. */
    private static function assetInfo(?int $assetId): ?string
    {
        $a = $assetId ? Asset::with('employee')->find($assetId) : null;
        if (! $a) return null;
        $who = $a->employee ? 'Pemegang: ' . $a->employee->name : 'Belum ada pemegang — komponen dipasang sebagai persiapan di stok gudang IT';
        $inst = $a->installedComponents()->map(fn ($r) => $r['component']?->name . ' ×' . (int) $r['qty'])->implode(', ');
        return $who . ' · Terpasang: ' . ($inst ?: 'belum ada');
    }

    /** @param bool $withComponent tampilkan pilihan komponen; @param bool $withAsset tampilkan pilihan aset */
    public static function fields(bool $withComponent, bool $withAsset, ?int $fixedAssetId = null): array
    {
        $assetOf = fn (Get $get) => $fixedAssetId ?: (int) $get('asset_id');
        return array_values(array_filter([
            $withComponent ? Select::make('component_id')->label('Komponen yang dipasang')->required()->searchable()->native(false)->options(fn () => static::componentOptions()) : null,
            $withAsset ? Select::make('asset_id')->label('Dipasang pada aset')->required()->searchable()->native(false)->live()->options(fn () => static::assetOptions())
                ->helperText(fn (Get $get) => static::assetInfo((int) $get('asset_id')) ?? 'Laptop / PC yang dipasangi, termasuk yang belum ada pemegangnya.') : null,
            TextInput::make('qty')->label('Jumlah dipasang')->numeric()->maxValue(1000000)->integer()->minValue(1)->default(1)->required(),
            Select::make('reason')->label('Alasan')->options(\App\Models\AccessoryHandover::INSTALL_REASONS)->required()->native(false)->default('Upgrade'),
            Select::make('old_id')->label('Komponen lama yang dilepas (opsional)')->searchable()->native(false)
                ->helperText('Mis. RAM 8 GB yang diganti. Hanya komponen yang tercatat terpasang pada aset ini.')
                ->options(function (Get $get) use ($assetOf) {
                    $a = Asset::find($assetOf($get));
                    return $a ? $a->installedComponents()->mapWithKeys(fn ($r) => [$r['component']->id => "{$r['component']->name} (terpasang " . (int) $r['qty'] . ')'])->all() : [];
                }),
            TextInput::make('old_qty')->label('Jumlah dilepas')->numeric()->maxValue(1000000)->integer()->minValue(1)->default(1),
            Select::make('old_condition')->label('Kondisi komponen lama')->native(false)->default('good')
                ->options(['good' => 'Baik — kembali ke stok tersedia', 'broken' => 'Rusak — dihapus dari stok (tidak kembali)']),
            Textarea::make('old_note')->label('Catatan komponen lama (mis. rusak)')->rows(2),
            Select::make('spec_key')->label('Perbarui spesifikasi aset (opsional)')->native(false)->placeholder('Tidak diubah')
                ->helperText('Mis. pilih RAM lalu isi "16 GB DDR4" agar spesifikasi laptop ikut terbarui; sebelum → sesudah tercatat di dokumen.')
                ->options(fn () => collect(Asset::specFields())->map(fn ($f) => $f[0])->all())->live(),
            TextInput::make('spec_value')->label('Nilai spesifikasi baru')->maxLength(255)
                ->visible(fn (Get $get) => filled($get('spec_key')))->required(fn (Get $get) => filled($get('spec_key')))
                ->datalist(fn (Get $get) => Asset::specFields()[$get('spec_key')][3] ?? []),
            Select::make('pic_id')->label('Peminta (opsional)')->searchable()->native(false)->options(fn () => Employee::where('status', 'active')->orderBy('name')->pluck('name', 'id'))
                ->helperText('User yang meminta upgrade, bila bukan pemegang aset. Pemegang aset dicatat otomatis.'),
            Select::make('request_id')->label('Request ITR (opsional)')->searchable()->native(false)->placeholder('Tidak terkait request')
                ->options(fn () => \App\Filament\Resources\AccessoryHandovers\AccessoryHandoverResource::requestOptions()),
            Textarea::make('notes')->label('Catatan (opsional)')->rows(2),
        ]));
    }

    /** Jalankan; sukses -> nomor dokumen + tombol cetak, gagal -> alasan dan aksi dibatalkan. */
    public static function run(Action $action, Asset $asset, array $data, ?string $title = null): ?\App\Models\AccessoryHandover
    {
        try {
            $h = app(AccessoryHandoverService::class)->installComponents(
                $asset,
                ! empty($data['component_id']) ? [['component_id' => $data['component_id'], 'qty' => $data['qty'] ?? 1]] : [],
                ! empty($data['old_id']) ? [['component_id' => $data['old_id'], 'qty' => $data['old_qty'] ?? 1, 'condition' => $data['old_condition'] ?? 'good', 'note' => $data['old_note'] ?? null]] : [],
                $data['reason'] ?? 'Upgrade', $data['notes'] ?? null,
                ! empty($data['request_id']) ? (int) $data['request_id'] : null, ! empty($data['pic_id']) ? (int) $data['pic_id'] : null,
                ['key' => $data['spec_key'] ?? '', 'value' => $data['spec_value'] ?? ''],
            );
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('Gagal memasang / melepas komponen')->body($e->getMessage())->danger()->send();
            $action->halt();
            return null;
        }
        Notification::make()->success()->title(($title ?? 'Komponen tercatat') . ' — ' . $h->bast_number)->body('Cetak bukti pemasangan, tanda tangani, lalu unggah scan-nya.')
            ->actions([Action::make('print')->label('Cetak Bukti')->url(route('accessory-bast.view', $h), shouldOpenInNewTab: true)->button()])->send();
        return $h;
    }

    /** Aksi per baris komponen ("Pasang ke Aset"): komponen = baris itu, pilih aset. */
    public static function row(): Action
    {
        return Action::make('install')->label('Pasang ke Aset')->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->visible(fn () => static::canManage())
            ->modalHeading(fn ($record) => 'Pasang ke aset: ' . $record->name)
            ->modalDescription('Untuk upgrade / perbaikan laptop atau PC. Membuat dokumen bukti pemasangan; aset yang belum ada pemegangnya juga bisa.')
            ->schema(static::fields(false, true))
            ->action(function (array $data, Component $record, Action $action) {
                static::run($action, Asset::findOrFail($data['asset_id']), ['component_id' => $record->id] + $data);
            });
    }

    /** Tombol header halaman Komponen: pilih komponen dan aset. */
    public static function header(): Action
    {
        return Action::make('install_header')->label('Pasang ke Aset')->icon(Heroicon::OutlinedWrenchScrewdriver)->color('info')
            ->visible(fn () => static::canManage())
            ->modalHeading('Pasang komponen ke aset')
            ->modalDescription('Mis. user minta upgrade RAM: pilih komponen (RAM baru) dan laptop-nya, lalu isi komponen lama yang dilepas bila ada.')
            ->schema(static::fields(true, true))
            ->action(fn (array $data, Action $action) => static::run($action, Asset::findOrFail($data['asset_id']), $data));
    }
}
