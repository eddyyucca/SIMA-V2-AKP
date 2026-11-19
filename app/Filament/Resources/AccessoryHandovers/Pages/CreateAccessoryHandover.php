<?php

namespace App\Filament\Resources\AccessoryHandovers\Pages;

use App\Filament\Resources\AccessoryHandovers\AccessoryHandoverResource;
use App\Models\Accessory;
use App\Models\Employee;
use App\Models\ITRequest;
use App\Services\AccessoryHandoverService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAccessoryHandover extends CreateRecord
{
    protected static string $resource = AccessoryHandoverResource::class;
    protected static ?string $title = 'Buat Serah Terima Barang Pendukung';

    /** Dibuka dari request ITR (?request=ID): karyawan dan daftar barang terisi dari request itu. */
    public function mount(): void
    {
        parent::mount();
        if (preg_match('/^(accessory|consumable|component):\d+$/', (string) request()->query('item'))) {
            $this->form->fill(['type' => 'checkout', 'target_type' => 'employee', 'lines' => [['item' => request()->query('item'), 'qty' => 1]]]);
            return;
        }
        $req = request()->integer('request') ? ITRequest::find(request()->integer('request')) : null;
        if (! $req || $req->type !== 'ITR') return;

        $emp = $req->employee_id ? Employee::find($req->employee_id) : ($req->user_id ? Employee::where('user_id', $req->user_id)->first() : null);
        $lines = [];
        $unmatched = [];
        // pencocokan oleh tim IT (halaman Proses Request) didahulukan; kalau belum ada, barang yang ditulis pemohon dicocokkan otomatis berdasarkan nama
        foreach ($req->links()->whereIn('kind', ['accessory', 'consumable', 'component'])->get() as $l) {
            $lines[] = ['item' => $l->kind . ':' . $l->item_id, 'qty' => $l->qty];
        }
        foreach ($lines ? [] : (array) $req->items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') continue;
            $acc = Accessory::where('name', $name)->first()
                ?? Accessory::where('name', 'like', '%' . $name . '%')->orderByDesc('qty_available')->first()
                ?? Accessory::whereRaw('lower(?) like CONCAT(\'%\', lower(name), \'%\')', [$name])->orderByDesc('qty_available')->first();
            $found = $acc ? 'accessory:' . $acc->id
                : (($c = \App\Models\Consumable::where('name', 'like', '%' . $name . '%')->first()) ? 'consumable:' . $c->id
                : (($k = \App\Models\Component::where('name', 'like', '%' . $name . '%')->first()) ? 'component:' . $k->id : null));
            if ($found) $lines[] = ['item' => $found, 'qty' => max(1, (int) ($item['qty'] ?? 1))];
            else $unmatched[] = $name;
        }
        $this->form->fill([
            'type' => 'checkout', 'target_type' => 'employee', 'employee_id' => $emp?->id, 'request_id' => $req->id,
            'lines' => $lines ?: [['item' => null, 'qty' => 1]],
            'notes' => 'Request ' . ($req->ticket_number ?? '#' . $req->id) . ' — ' . $req->title,
        ]);
        if (! $emp) {
            Notification::make()->warning()->title('Peminta belum tertaut ke data karyawan')->body('Pilih karyawan penerima secara manual.')->send();
        }
        if ($unmatched) {
            Notification::make()->warning()->title('Barang belum cocok dengan daftar Accessories / Consumable / Komponen')->body('Pilih manual: ' . implode(', ', $unmatched))->send();
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        $svc = app(AccessoryHandoverService::class);
        try {
            $employee = ! empty($data['employee_id']) ? Employee::findOrFail($data['employee_id']) : null;
            if ($data['type'] === 'return') {
                return $svc->receiveBack($employee, $data['lines'], $data['notes'] ?? null, [
                    'type' => $data['target_type'] ?? 'employee', 'department_id' => $data['department_id'] ?? null,
                    'location_id' => $data['location_id'] ?? null, 'asset_id' => $data['asset_id'] ?? null,
                ]);
            }
            return $svc->checkout($employee, $data['lines'], ! empty($data['request_id']) ? (int) $data['request_id'] : null, $data['notes'] ?? null, [
                'type' => $data['target_type'] ?? 'employee', 'department_id' => $data['department_id'] ?? null,
                'location_id' => $data['location_id'] ?? null, 'asset_id' => $data['asset_id'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('Gagal membuat serah terima')->body($e->getMessage())->danger()->send();
            $this->halt();
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()->success()->title('BAST ' . $this->record->bast_number . ' dibuat')
            ->body('Cetak, tanda tangani kedua pihak, lalu unggah scan-nya.')
            ->actions([Action::make('print')->label('Cetak BAST')->url(route('accessory-bast.view', $this->record), shouldOpenInNewTab: true)->button()]);
    }

    protected function getRedirectUrl(): string
    {
        return AccessoryHandoverResource::getUrl('index');
    }
}
