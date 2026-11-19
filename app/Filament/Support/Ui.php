<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class Ui
{
    /** Jalankan aksi; error bisnis tampil sebagai notifikasi merah dan aksi dibatalkan. */
    public static function run(callable $fn, string $ok, ?Action $action = null): mixed
    {
        try {
            $r = $fn();
            Notification::make()->title($ok)->success()->send();
            return $r;
        } catch (InvalidArgumentException|ValidationException $e) {
            Notification::make()->title($action ? 'Gagal: ' . $action->getLabel() : 'Gagal')->body($e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage())->danger()->send();
            $action?->halt();
            return null;
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            [$title, $body] = \App\Support\FriendlyErrors::fromQuery($e);
            Notification::make()->title($title)->body($body)->danger()->persistent()->send();
            $action?->halt();
            return null;
        }
    }

    public static function rp(): string { return 'IDR'; }
}
