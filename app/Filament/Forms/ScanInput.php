<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\Field;

/** Input teks dengan tombol scan kamera (html5-qrcode); input manual tetap tersedia. */
class ScanInput extends Field
{
    protected string $view = 'filament.forms.scan-input';
}
