<?php

namespace App\Services;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Component;
use App\Models\Consumable;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class LabelService
{
    /** Jenis yang punya label cetak. Consumable tidak dilabeli (kodenya tetap bisa discan dari label lama lewat resolve()). */
    public const KINDS = ['asset' => 'Aset', 'accessory' => 'Accessories', 'component' => 'Komponen'];
    private const MM = 2.834645669;   // mm -> pt

    private const HB = [   // lebar huruf Helvetica Bold per 1000 em
        'A' => 722, 'B' => 722, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278, 'J' => 556, 'K' => 722, 'L' => 611, 'M' => 833,
        'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611,
        'a' => 556, 'b' => 611, 'c' => 556, 'd' => 611, 'e' => 556, 'f' => 333, 'g' => 611, 'h' => 611, 'i' => 278, 'j' => 278, 'k' => 556, 'l' => 278, 'm' => 889,
        'n' => 611, 'o' => 611, 'p' => 611, 'q' => 611, 'r' => 389, 's' => 556, 't' => 333, 'u' => 611, 'v' => 556, 'w' => 778, 'x' => 556, 'y' => 556, 'z' => 500,
        '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556,
        ' ' => 278, '.' => 278, ',' => 278, '-' => 333, '/' => 278, ':' => 333, '(' => 333, ')' => 333, '&' => 722, '_' => 556, '#' => 556, '+' => 584, '…' => 1000,
    ];

    public static function model(string $kind): string
    {
        return match ($kind) {
            'asset' => Asset::class, 'accessory' => Accessory::class, 'component' => Component::class,
            default => abort(404),
        };
    }

    /** Ukuran bawaan per jenis: aset = pita 24 mm berbingkai; Accessories / Komponen = pita 9 mm. */
    public static function defaults(string $kind): array
    {
        return $kind === 'asset'
            ? ['tape' => (int) config('labels.tape'), 'length' => (int) config('labels.length')]
            : ['tape' => (int) config('labels.other.tape'), 'length' => (int) config('labels.other.length')];
    }

    /** Kode yang dicetak sebagai QR: kode aset, atau ACC-0001 / CSM-0001 / CMP-0001. */
    public static function code(string $kind, $m): string
    {
        return $kind === 'asset' ? $m->asset_tag : $m->code;
    }

    /** Isi QR pada label: selalu kode barang (bukan alamat/tautan), apa pun nilai LABEL_QR_MODE. */
    public static function qrPayload(string $code): string
    {
        return $code;
    }

    /** Cari barang dari hasil scan: kode aset (termasuk kode lama), serial number, kode ACC-/CSM-/CMP-, atau alamat dari QR. */
    public static function resolve(string $scan): ?array
    {
        $scan = Asset::extractCode($scan);
        if ($scan === '') return null;
        if ($a = Asset::findByCode($scan)) return ['asset', $a];
        if (preg_match('/^(.+)-(\d+)$/', $scan, $m)) {
            $id = (int) $m[2];
            $pre = strtoupper($m[1]);
            $models = ['accessory' => Accessory::class, 'consumable' => Consumable::class, 'component' => Component::class];
            foreach ($models as $kind => $cls) {
                if (in_array($pre, \App\Support\CodePrefix::all($kind), true)) return ($x = $cls::find($id)) ? [$kind, $x] : null;
            }
        }
        return null;
    }

    public function items(string $kind, array $ids): Collection
    {
        return self::model($kind)::query()->whereIn('id', $ids)->orderBy('id')->get();
    }

    // ------------------------------------------------------------------ ukuran teks
    /** Lebar teks Helvetica Bold pada $pt (mm). */
    public static function textWidthMm(string $text, float $pt): float
    {
        $units = 0;
        foreach (mb_str_split($text) as $ch) $units += self::HB[$ch] ?? 600;
        return $units / 1000 * $pt * 0.3528;
    }

    /** Potong teks (dengan "…") hingga muat di $widthMm pada ukuran huruf minimum yang masih terbaca. */
    public static function truncateToFit(string $text, float $widthMm, float $minPt = 6.5): string
    {
        $text = trim($text);
        while (mb_strlen($text) > 2 && self::textWidthMm($text, $minPt) > $widthMm * 0.97) {
            $text = rtrim(mb_substr(rtrim($text, '…'), 0, -1)) . '…';
        }
        return $text;
    }

    /** Ukuran huruf (pt) terbesar (≤ $maxPt) agar teks mengisi 97% $widthMm. */
    public static function fit(string $text, float $widthMm, float $maxPt): float
    {
        $perPt = max(0.0001, self::textWidthMm($text, 1));
        return round(max(4.0, min($maxPt, $widthMm * 0.97 / $perPt)), 1);
    }

    private static function qr(string $code): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(QrCode::format('svg')->size(300)->margin(0)->errorCorrection('M')->generate(self::qrPayload($code)));
    }

    // ------------------------------------------------------------------ PDF
    public const ORIENTATIONS = ['landscape' => 'Mendatar (bawaan)', 'portrait' => 'Tegak, diputar kanan', 'portrait-ccw' => 'Tegak, diputar kiri'];

    /**
     * $orient: 'landscape' = halaman panjang × lebar pita (bawaan). 'portrait' / 'portrait-ccw' = halaman lebar pita × panjang,
     * isi label diputar 90° (kanan / kiri). Sebagian driver Brother hanya mengenal ukuran kertas tegak (lebar = pita).
     */
    public function pdf(string $kind, Collection $items, int $tape, int $length, int $copies = 1, string $orient = 'landscape')
    {
        if (! isset(self::ORIENTATIONS[$orient])) $orient = 'landscape';
        abort_unless(in_array($tape, config('labels.tapes'), true), 422);
        $length = max(20, min(300, $length));
        $copies = max(1, min(20, $copies));
        return $tape >= 18 ? $this->framed($kind, $items, $tape, $length, $copies, $orient) : $this->compact($kind, $items, $tape, $length, $copies, $orient);
    }

    private function paper($pdf, int $tape, int $length, string $orient = 'landscape')
    {
        return $orient === 'landscape'
            ? $pdf->setPaper([0, 0, $length * self::MM, $tape * self::MM])
            : $pdf->setPaper([0, 0, $tape * self::MM, $length * self::MM]);
    }

    // ---- pita >= 18 mm: satu bingkai (perusahaan / kode / serial-nama di kiri, QR di kanan) ----
    /**
     * Geometri label berbingkai (mm), diskalakan terhadap lebar pita 24 mm. Satu bingkai luar; di dalamnya kolom kiri tiga baris
     * (dipisah garis) dan sel QR persegi di kanan, dipisah satu garis tegak. Bingkai berada di area cetak aman (margin $c dari tepi pita).
     * Kunci: iw/ih = lebar/tinggi dalam bingkai; leftW = lebar kolom kiri; qb/qx = sisi dan posisi sel QR (termasuk garis pemisahnya).
     */
    public static function frame(int $tape, int $length): array
    {
        $s = $tape / 24;
        $bw = max(0.3, round(0.45 * $s, 2));               // garis pemisah dalam
        $ob = round($bw * 1.4, 2);                         // garis bingkai luar sedikit lebih tebal
        $c = round(2.7 * $s, 2);
        $ch = round($tape - 2 * $c, 2);                    // tinggi bingkai (luar)
        $iw = round($length - 2 * $c - 2 * $ob, 2);        // lebar dalam bingkai
        $ih = round($ch - 2 * $ob, 2);                     // tinggi dalam bingkai
        $qb = $ih;                                         // sel QR persegi setinggi bagian dalam
        $leftW = round($iw - $qb, 2);                      // kolom kiri (garis pemisah tegak milik sel QR)
        $h1 = round(0.45 * $ih - $bw, 2);
        $h2 = round(0.275 * $ih - $bw, 2);
        $h3 = round($ih - ($h1 + $bw) - ($h2 + $bw), 2);
        $pad = round(1.3 * $s, 2);
        return ['s' => $s, 'bw' => $bw, 'ob' => $ob, 'c' => $c, 'ch' => $ch, 'iw' => $iw, 'ih' => $ih, 'qb' => $qb, 'qx' => round($c + $ob + $leftW, 2),
            'shift' => -0.05, 'leftW' => $leftW, 'h1' => $h1, 'h2' => $h2, 'h3' => $h3, 'pad' => $pad, 'qpad' => round(0.9 * $s, 2),
            'textW' => round($leftW - 2 * $pad, 2)];
    }

    private function framed(string $kind, Collection $items, int $tape, int $length, int $copies, string $orient = 'landscape')
    {
        $G = self::frame($tape, $length);
        $company = self::truncateToFit(strtoupper((string) config('labels.company')), $G['textW']);
        $labels = [];
        foreach ($items as $m) {
            $code = self::code($kind, $m);
            // tanpa keterangan: baris 2 = kode, baris 3 = serial number (aset) / nama (barang lain)
            $c2 = self::truncateToFit(strtoupper($code), $G['textW']);
            $c3 = self::truncateToFit(strtoupper((string) ($kind === 'asset' ? $m->labelSerialText() : $m->name)), $G['textW']);
            $maxRow = $G['h2'] * 2.45;
            $f1 = self::fit($company, $G['textW'], $G['h1'] * 2.45);
            // kode & serial sedikit lebih kecil (90%) dari nama perusahaan supaya nama PT yang menonjol
            $common = round(min(self::fit($c2, $G['textW'], $maxRow), self::fit($c3, $G['textW'], $maxRow), $f1 * 0.9), 1);
            $labels[] = ['company' => $company, 'c2cap' => '', 'c2' => $c2, 'c3cap' => '', 'c3' => $c3,
                'f1' => $f1, 'f2' => $common, 'f3' => $common, 'qrsvg' => self::qr($code)];
        }
        $labels = collect($labels)->flatMap(fn ($l) => array_fill(0, $copies, $l))->all();
        return $this->paper(Pdf::loadView('pdf.label-framed', ['labels' => $labels, 'G' => $G, 'tape' => $tape, 'length' => $length, 'orient' => $orient]), $tape, $length, $orient);
    }

    // ---- pita < 18 mm (mis. 9 mm): ringkas. Accessories / Komponen: QR + "PT. AKP" di atas + kode (ACC-0001) di bawahnya ----
    /** Nama singkat perusahaan pada label Accessories / Komponen (LABEL_OTHER_COMPANY). */
    public static function shortCompany(): string
    {
        return strtoupper(trim((string) config('labels.other.company'))) ?: 'PT. AKP';
    }

    /** Geometri label ringkas (mm). */
    public static function compactGeometry(int $tape, int $length): array
    {
        $border = $tape >= 9;
        $bw = $border ? 0.3 : 0.0;
        $inset = $border ? 0.25 : 0.0;
        $e = round($inset + $bw + ($tape >= 12 ? 1.1 : ($tape >= 9 ? 0.6 : 0.5)), 2);   // tepi area isi
        $qr = round($tape - 2 * $e, 2);
        $textX = round($e + $qr + ($tape >= 9 ? 1.4 : 1.0), 2);
        $textW = round($length - $textX - $e - 0.3, 2);
        $rows = $tape >= 9 ? 2 : 1;
        $rowH = round($qr / $rows, 2);
        return ['border' => $border, 'bw' => $bw, 'inset' => $inset, 'e' => $e, 'qr' => $qr, 'textX' => $textX, 'textW' => $textW, 'rows' => $rows, 'rowH' => $rowH, 'shift' => -0.05];
    }

    private function compact(string $kind, Collection $items, int $tape, int $length, int $copies, string $orient = 'landscape')
    {
        $G = self::compactGeometry($tape, $length);
        $maxPt = $G['rowH'] * 2.35;
        $labels = [];
        foreach ($items as $m) {
            $code = self::code($kind, $m);
            if ($kind === 'asset') {
                // aset pada pita kecil: kode di atas, serial / merek di bawah
                $c1 = self::truncateToFit(strtoupper($code), $G['textW'], 5.0);
                $c2 = $G['rows'] > 1 ? self::truncateToFit(strtoupper((string) ($m->serial_number ?: trim("{$m->brand} {$m->model}"))), $G['textW'], 5.0) : '';
                $f1 = self::fit($c1, $G['textW'], $maxPt);
                $f2 = $c2 === '' ? 0 : self::fit($c2, $G['textW'], $maxPt * 0.9);
            } elseif ($G['rows'] > 1) {
                // Accessories / Komponen: "PT. AKP" di atas, nomor kode di bawah; ukuran huruf sama supaya rapi
                $c1 = self::shortCompany();
                $c2 = self::truncateToFit(strtoupper($code), $G['textW'], 5.0);
                $f1 = $f2 = round(min(self::fit($c1, $G['textW'], $maxPt), self::fit($c2, $G['textW'], $maxPt)), 1);
            } else {
                // pita sangat kecil (satu baris): hanya nomor kode
                $c1 = self::truncateToFit(strtoupper($code), $G['textW'], 5.0);
                $c2 = '';
                $f1 = self::fit($c1, $G['textW'], $maxPt);
                $f2 = 0;
            }
            $labels[] = ['c1' => $c1, 'c2' => $c2, 'f1' => $f1, 'f2' => $f2, 'qrsvg' => self::qr($code)];
        }
        $labels = collect($labels)->flatMap(fn ($l) => array_fill(0, $copies, $l))->all();
        return $this->paper(Pdf::loadView('pdf.label-compact', ['labels' => $labels, 'G' => $G, 'tape' => $tape, 'length' => $length, 'orient' => $orient]), $tape, $length, $orient);
    }
}
