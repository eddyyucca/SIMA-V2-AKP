<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/** Identitas aplikasi: nama dan logo (dipakai panel, PDF, dan email). */
class Branding
{
    public const NAME = 'IT Asset & Inventory Management';
    public const LOGO = 'images/logo-akp.png';

    public static function logoPath(): string { return public_path(self::LOGO); }

    public static function logoUrl(): string { return asset(self::LOGO); }

    /** Logo AKP + nama aplikasi di sampingnya (menu samping, bilah atas, halaman login). */
    public static function brandHtml(): HtmlString
    {
        return new HtmlString(
            '<span style="display:inline-flex;align-items:center;gap:.8rem;height:100%">'
            . '<img class="akp-mark" src="' . e(self::logoUrl()) . '" alt="AKP" style="height:2.1rem;width:auto">'
            . '<span class="akp-brand-text" style="font-weight:700;font-size:1.6rem;line-height:1.2;letter-spacing:-.01em;white-space:nowrap">IT Asset &amp; Inventory Management</span>'
            . '</span>'
        );
    }

    /** Logo sebagai data URI agar tampil di PDF (DomPDF) tanpa akses jaringan. */
    public static function logoDataUri(): string
    {
        static $uri = null;
        if ($uri === null) {
            $f = self::logoPath();
            $uri = is_file($f) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($f)) : '';
        }
        return $uri;
    }
}
