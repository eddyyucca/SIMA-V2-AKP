<?php

namespace Tests\Qa;

use App\Support\FriendlyErrors;
use Illuminate\Database\QueryException;
use Livewire\Component;
use Livewire\Livewire;
use Tests\QaTestCase;

class FailingDbComponent extends Component
{
    public function save(): void
    {
        \Illuminate\Support\Facades\DB::table('accessories')->insert(['name' => 'x', 'qty_total' => null]);
    }

    public function render(): string { return '<div>ok</div>'; }
}

/** Error database ditampilkan sebagai kalimat yang jelas, bukan "Terjadi error ketika memuat halaman". */
class Qa47_FriendlyErrorsTest extends QaTestCase
{
    private function ex(int $code, string $msg): QueryException
    {
        $e = new QueryException('mariadb', 'insert ...', [], new \PDOException($msg));
        $e->errorInfo = ['23000', $code, $msg];
        return $e;
    }

    public function test_known_database_errors_have_clear_messages(): void
    {
        [$t, $b] = FriendlyErrors::fromQuery($this->ex(1048, "Column 'qty_total' cannot be null"));
        $this->assertSame('Ada isian wajib yang masih kosong', $t);
        $this->assertStringContainsString('Qty Total', $b);
        [$t, $b] = FriendlyErrors::fromQuery($this->ex(1062, "Duplicate entry 'AKP-001' for key 'x'"));
        $this->assertSame('Data sudah ada', $t);
        $this->assertStringContainsString('AKP-001', $b);
        $this->assertSame('Data tidak bisa dihapus', FriendlyErrors::fromQuery($this->ex(1451, 'fk'))[0]);
        $this->assertSame('Angka terlalu besar atau kecil', FriendlyErrors::fromQuery($this->ex(1264, 'range'))[0]);
        $this->assertSame('Data belum bisa disimpan', FriendlyErrors::fromQuery($this->ex(9999, 'x'))[0]);
    }

    public function test_livewire_database_error_becomes_a_notification_not_a_crash(): void
    {
        $this->as($this->admin());
        config(['app.friendly_errors_in_tests' => true]);
        Livewire::test(FailingDbComponent::class)->call('save')->assertOk()
            ->assertNotified(\Filament\Notifications\Notification::make()->title('Ada isian wajib yang masih kosong')
                ->body('Kolom "Qty Total" belum diisi. Isi kolom tersebut (angka boleh 0), lalu simpan lagi.')->danger()->persistent());
    }

    public function test_optional_number_fields_left_empty_are_saved_as_zero(): void
    {
        $this->as($this->admin());
        \Livewire\Livewire::test(\App\Filament\Resources\Accessories\Pages\ManageAccessories::class)
            ->callAction('create', ['name' => 'Kosong Uji', 'qty_total' => null, 'min_qty' => '', 'unit_price' => null])
            ->assertHasNoActionErrors();
        $a = \App\Models\Accessory::where('name', 'Kosong Uji')->firstOrFail();
        $this->assertSame(0, (int) $a->qty_total);
        $this->assertSame(0, (int) $a->min_qty);
    }

    public function test_negative_and_huge_numbers_are_rejected_with_a_message(): void
    {
        $this->as($this->admin());
        Livewire::test(\App\Filament\Resources\Consumables\Pages\ManageConsumables::class)
            ->callAction('create', ['name' => 'Angka Uji', 'unit' => 'pcs', 'qty' => -1, 'min_qty' => 0, 'unit_price' => 99999999999999])
            ->assertHasActionErrors(['qty', 'unit_price']);
    }
}
