<?php

namespace App\Models\Concerns;

use App\Support\SerialNumber;

/** Lapisan terakhir: serial dirapikan, dan duplikat ditolak walau data masuk lewat impor/seeder/kode lain. */
trait HasUniqueSerial
{
    protected static function bootHasUniqueSerial(): void
    {
        static::saving(function ($m) {
            $m->serial_number = SerialNumber::clean($m->serial_number);
            if ($m->serial_number !== null && ($m->isDirty('serial_number') || ! $m->exists)
                && ($h = SerialNumber::holder($m->serial_number, $m))) {
                throw new \InvalidArgumentException(SerialNumber::message($m->serial_number, $h));
            }
        });
    }
}
