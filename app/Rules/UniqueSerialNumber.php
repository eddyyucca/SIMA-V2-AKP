<?php

namespace App\Rules;

use App\Support\SerialNumber;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/** Tolak serial number yang sudah dipakai aset/komponen lain, dengan pesan di kolom dan notifikasi merah. */
class UniqueSerialNumber implements ValidationRule
{
    public function __construct(private readonly ?Model $ignore = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $sn = SerialNumber::clean($value);
        if ($sn === null || ! ($holder = SerialNumber::holder($sn, $this->ignore))) return;

        $msg = SerialNumber::message($sn, $holder);
        try {
            Notification::make()->danger()->title('Serial number duplikat')->body($msg)->persistent()->send();
        } catch (\Throwable) {
            // tanpa sesi (mis. konsol): pesan di kolom tetap tampil
        }
        $fail($msg);
    }
}
