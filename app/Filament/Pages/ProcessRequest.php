<?php

namespace App\Filament\Pages;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\ArrivalPlanItem;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ITRequest;
use App\Models\RequestItemLink;
use App\Services\AccessoryHandoverService;
use App\Services\RequestProcessingService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Pemrosesan request ITERA oleh tim IT. Pemohon mengisi manual (teks bebas); di sini IT mencocokkan dengan database:
 * karyawan, aset, accessories, consumable, komponen, menyesuaikan data, lalu memproses (BAST serah terima / peminjaman aset).
 */
class ProcessRequest extends Page
{
    protected static ?string $title = 'Proses Request IT';
    protected static ?string $slug = 'proses-request';
    protected string $view = 'filament.pages.process-request';

    public int $requestId = 0;
    public ?int $employeeId = null;
    public array $fields = [];
    /** @var list<array{id:?int,kind:string,item_id:?int,qty:mixed,note:?string,processed:bool}> */
    public array $links = [];

    public static function shouldRegisterNavigation(): bool { return false; }

    public static function canAccess(): bool { return auth()->user()?->isAdmin() ?? false; }

    public static function urlFor(ITRequest|int $r): string
    {
        return static::getUrl(['request' => $r instanceof ITRequest ? $r->id : $r]);
    }

    public function mount(): void
    {
        $this->requestId = ITRequest::findOrFail(request()->integer('request'))->id;
        $this->load();
    }

    /** Muat ulang isian dari database (dipanggil juga setelah simpan; id request sudah tersimpan di komponen, tidak bergantung URL). */
    private function load(): void
    {
        $r = ITRequest::with('links')->findOrFail($this->requestId);
        $this->employeeId = app(RequestProcessingService::class)->suggestEmployee($r)?->id;
        $this->fields = [
            'requester_name' => (string) $r->requester_name, 'nik' => (string) $r->nik, 'department' => (string) $r->department, 'position' => (string) $r->position,
            'title' => (string) $r->title, 'description' => (string) $r->description,
            'loan_start_date' => $r->loan_start_date?->toDateString(), 'loan_end_date' => $r->loan_end_date?->toDateString(),
        ];
        $this->links = $r->links->map(fn ($l) => ['id' => $l->id, 'kind' => $l->kind, 'item_id' => $l->item_id, 'qty' => $l->qty, 'note' => $l->note, 'processed' => $l->processed_at !== null])->all();
        if (! $this->links) $this->links = [['id' => null, 'kind' => in_array($r->type, ['PEMINJAMAN_ASET', 'BAK', 'TICKET_SISTEM'], true) ? 'asset' : '', 'item_id' => null, 'qty' => 1, 'note' => null, 'processed' => false]];
    }

    public function record(): ITRequest { return ITRequest::with(['links', 'employee', 'accessoryHandovers'])->findOrFail($this->requestId); }

    public function departments(): array
    {
        $list = Department::names();
        $cur = $this->fields['department'] ?? '';
        if ($cur !== '' && ! in_array($cur, $list, true)) array_unshift($list, $cur);
        return $list;
    }

    public function employees(): array
    {
        return Employee::where('status', 'active')->orWhere('id', $this->employeeId)->orderBy('name')->get()
            ->mapWithKeys(fn ($e) => [$e->id => $e->name . ($e->nik ? ' · ' . $e->nik : '')])->all();
    }

    /** Pilihan barang per jenis. Aset: yang Tersedia dan tidak dicadangkan (ditambah yang sudah ditautkan ke request ini). */
    public function options(string $kind): array
    {
        $own = collect($this->links)->where('kind', $kind)->pluck('item_id')->filter()->all();
        if ($kind === 'asset' && in_array($this->record()->type, ['BAK', 'TICKET_SISTEM'], true)) {
            // laporan kerusakan: aset yang dipegang pelapor (bukan hanya yang Tersedia), ditambah yang sudah ditautkan
            return Asset::query()->where(fn ($q) => $q->when($this->employeeId, fn ($w) => $w->where('employee_id', $this->employeeId))->orWhereIn('id', $own))
                ->whereNotIn('status', Asset::INACTIVE)->orderBy('asset_tag')->get()
                ->mapWithKeys(fn ($a) => [$a->id => $a->asset_tag . ' — ' . trim($a->brand . ' ' . $a->model) . ' (' . (Asset::STATUSES[$a->status] ?? $a->status) . ')'])->all();
        }
        return match ($kind) {
            'asset' => Asset::query()->where(fn ($q) => $q->where(fn ($w) => $w->where('status', 'available')->whereNull('employee_id')
                    ->whereNotIn('id', ArrivalPlanItem::whereIn('status', ArrivalPlanItem::RESERVING)->whereNotNull('asset_id')->pluck('asset_id')))->orWhereIn('id', $own))
                ->orderBy('asset_tag')->get()->mapWithKeys(fn ($a) => [$a->id => $a->asset_tag . ' — ' . trim($a->brand . ' ' . $a->model) . ' (' . (Asset::STATUSES[$a->status] ?? $a->status) . ')'])->all(),
            'accessory' => Accessory::orderBy('name')->get()->mapWithKeys(fn ($i) => [$i->id => $i->code . ' — ' . $i->name . ' (stok ' . $i->qty_available . ')'])->all(),
            'consumable' => Consumable::orderBy('name')->get()->mapWithKeys(fn ($i) => [$i->id => $i->code . ' — ' . $i->name . ' (stok ' . (float) $i->qty . ' ' . $i->unit . ')'])->all(),
            'component' => Component::orderBy('name')->get()->mapWithKeys(fn ($i) => [$i->id => $i->code . ' — ' . $i->name . ' (stok ' . $i->qty_available . ')'])->all(),
            default => [],
        };
    }

    public function updatedLinks($value, $key): void
    {
        if (str_ends_with((string) $key, '.kind')) {
            $i = (int) explode('.', (string) $key)[0];
            $this->links[$i]['item_id'] = null;
        }
    }

    public function addLink(): void
    {
        $this->links[] = ['id' => null, 'kind' => in_array($this->record()->type, ['PEMINJAMAN_ASET', 'BAK', 'TICKET_SISTEM'], true) ? 'asset' : '', 'item_id' => null, 'qty' => 1, 'note' => null, 'processed' => false];
    }

    public function removeLink(int $i): void
    {
        if (! isset($this->links[$i]) || ($this->links[$i]['processed'] ?? false)) return;
        unset($this->links[$i]);
        $this->links = array_values($this->links);
    }

    private function persist(): bool
    {
        $r = $this->record();
        $rules = ['fields.requester_name' => ['required', 'string', 'max:255'], 'fields.nik' => ['nullable', 'regex:/^[0-9]*$/', 'max:50'],
            'fields.department' => ['nullable', 'string', 'max:100'], 'fields.position' => ['nullable', 'string', 'max:100'], 'fields.title' => ['required', 'string', 'max:255'],
            'fields.description' => ['nullable', 'string', 'max:5000'], 'fields.loan_start_date' => ['nullable', 'date'], 'fields.loan_end_date' => ['nullable', 'date', 'after_or_equal:fields.loan_start_date'],
            'employeeId' => ['nullable', 'integer'], 'links.*.qty' => ['nullable', 'numeric', 'min:0.01', 'max:1000000'], 'links.*.note' => ['nullable', 'string', 'max:250']];
        $this->validate($rules, [
            'fields.requester_name.required' => 'Nama pemohon wajib diisi.', 'fields.title.required' => 'Judul request wajib diisi.', 'fields.nik.regex' => 'NIK hanya boleh berisi angka.',
            'fields.loan_end_date.after_or_equal' => 'Tanggal kembali tidak boleh sebelum tanggal pinjam.', 'links.*.qty.min' => 'Jumlah harus lebih dari 0.', 'links.*.qty.numeric' => 'Jumlah harus berupa angka.',
            'links.*.qty.max' => 'Jumlah terlalu besar.', 'links.*.note.max' => 'Catatan maksimal 250 karakter.',
        ]);
        try {
            app(RequestProcessingService::class)->save($r, $this->fields, $this->employeeId ?: null, $this->links);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('Gagal menyimpan pencocokan data')->body($e->getMessage())->danger()->send();
            return false;
        }
        $this->load();
        return true;
    }

    public function save(): void
    {
        if ($this->persist()) Notification::make()->success()->title('Pencocokan data disimpan')->body('Data pemohon dan barang database untuk request ini sudah diperbarui.')->send();
    }

    public function saveAndBorrow(): void
    {
        if (! $this->persist()) return;
        try {
            $n = app(RequestProcessingService::class)->borrow($this->record());
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('Gagal meminjamkan aset')->body($e->getMessage())->danger()->send();
            return;
        }
        $this->load();
        Notification::make()->success()->title("$n aset dipinjamkan")->body('Status aset menjadi Dipinjam dan BAST peminjaman dibuat. Saat aset kembali, tandai "Sudah Kembali" di daftar Peminjaman Aset.')->send();
    }

    public function saveAndRepair(): void
    {
        if (! $this->persist()) return;
        try {
            $n = app(RequestProcessingService::class)->report($this->record());
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('Gagal membuat tiket perbaikan')->body($e->getMessage())->danger()->send();
            return;
        }
        $this->load();
        Notification::make()->success()->title("$n tiket perbaikan dibuat")->body('Status aset menjadi Perbaikan. Saat perbaikan ditandai selesai di menu Perbaikan, request ini otomatis Completed.')->send();
    }

    public function toHandover()
    {
        if (! $this->persist()) return null;
        $r = $this->record();
        if ($r->type !== 'ITR' || ! in_array($r->status, AccessoryHandoverService::REQUEST_STATUSES, true)) {
            Notification::make()->title('Belum bisa membuat BAST')->body('Request harus bertipe ITR dan berstatus Approved atau In Progress.')->warning()->send();
            return null;
        }
        if (! $r->links->whereIn('kind', ['accessory', 'consumable', 'component'])->count()) {
            Notification::make()->title('Belum ada barang pendukung')->body('Pilih minimal satu Accessories, Consumable, atau Komponen. Aset (laptop dan sejenisnya) diserahkan lewat halaman aset.')->warning()->send();
            return null;
        }
        return redirect(\App\Filament\Resources\AccessoryHandovers\AccessoryHandoverResource::getUrl('create', ['request' => $r->id]));
    }
}
