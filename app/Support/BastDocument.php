<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/** Menyusun data dokumen BAST (A4) dari satu catatan serah terima / mutasi / pengembalian, atau dari formulir kosong. */
class BastDocument
{
    public const TITLES = [
        'checkout' => 'BERITA ACARA SERAH TERIMA ASSET IT',
        'checkin' => 'BERITA ACARA PENGEMBALIAN ASSET IT',
        'transfer' => 'BERITA ACARA MUTASI ASSET IT',
    ];

    public const STATEMENTS = [
        'Asset perusahaan yang diserahterimakan hanya diperuntukkan untuk mendukung kegiatan operasional dan kepentingan pekerjaan resmi perusahaan.',
        'Penerima bertanggung jawab penuh atas penggunaan, pemeliharaan, dan keamanan asset yang diterima, termasuk menjaga kondisi asset agar tetap baik.',
        'Penerima dilarang memindahtangankan, meminjamkan, mengalihkan penggunaan, ataupun menyerahkan asset kepada pihak lain tanpa persetujuan IT.',
        'Setiap kehilangan, kerusakan, maupun indikasi penyalahgunaan asset wajib segera dilaporkan kepada IT maksimal dalam waktu 1 x 24 jam sejak kejadian.',
        'Asset wajib dikembalikan kepada perusahaan dalam kondisi lengkap pada saat penerima mengalami resign, mutasi dan pergantian asset.',
        'Kerusakan asset akibat kelalaian menjadi tanggung jawab penerima, dan penggantian wajib disertai Berita Acara Kerusakan yang disetujui atasan pengguna.',
        'Dilarang keras dengan sengaja melepas, merusak, atau menghilangkan sticker label identitas yang terpasang pada asset.',
    ];

    /** Pengaturan dokumen (menu Pengaturan); bawaan mengikuti contoh formulir perusahaan. */
    public const SETTINGS = [
        'bast_company' => ['PT. ADHI KARTIKO PRATAMA TBK', 'Nama perusahaan di kop'],
        'bast_revision' => ['Rev.00', 'Revisi dokumen'],
        'bast_status' => ['Internal', 'Status dokumen'],
        'bast_superintendent' => ['M. RASYID RIDHO', 'Menyetujui: Superintendent'],
        'bast_acc_known' => ['M. RASYID RIDHO', 'Bukti serah terima barang pendukung — nama "Mengetahui" (kosongkan = tanda tangan manual)'],
        'bast_manager' => ['TOTOK SUPRAPTO', 'Mengetahui: Manager'],
        'bast_manager_ekspat' => ['MIN KYUN MIN', 'Mengetahui: Manager Ekspat'],
        'bast_gm' => ['RECKIE O.T.', 'Mengetahui: GM'],
        'bast_gm_ekspat' => ['LEE SEUNG YEON', 'Mengetahui: GM Ekspat'],
    ];

    /** Nama kelengkapan dalam SATU baris (tabel hanya memuat sedikit baris per halaman): nama, jumlah, dan nomor seri bila masih muat. */
    private static function equipmentName($c, int $qty): string
    {
        $base = $c->name . ($qty > 1 ? ' × ' . $qty : '');
        $withSn = $c->serial_number ? $base . ' · SN ' . $c->serial_number : $base;
        return mb_strlen($withSn) <= 38 ? $withSn : self::cut($base, 38);
    }

    /** Jumlah maksimum baris kelengkapan pada BAST (satu halaman A4); selebihnya diringkas. */
    public const MAX_EQUIPMENT_ROWS = 4;

    public static function setting(string $key): string
    {
        return (string) Setting::get($key, self::SETTINGS[$key][0]);
    }

    /** Potong teks panjang agar dokumen tetap satu halaman A4 (data lengkap ada di aplikasi). */
    private static function cut(?string $s, int $max): string
    {
        return mb_strimwidth((string) $s, 0, $max, '…');
    }

    private static function party(?Employee $e, string $blank): array
    {
        return [
            'name' => self::cut($e?->name ?: $blank, 60), 'nik' => self::cut($e?->nik ?: ($blank ?: '-'), 40),
            'position' => self::cut($e?->position ?: ($blank ?: '-'), 60), 'dept' => self::cut($e?->department?->name ?: ($blank ?: '-'), 60),
        ];
    }

    private static function it(AssetAssignment $a, string $blank): array
    {
        $u = $a->creator;
        $e = $u?->employee;
        return [
            'name' => self::cut($u?->name ?: $blank, 60), 'nik' => self::cut($e?->nik ?: ($blank ?: '-'), 40),
            'position' => self::cut($e?->position ?: ($u ? 'Staff IT' : ($blank ?: '-')), 60), 'dept' => self::cut($e?->department?->name ?: ($blank ?: '-'), 60),
        ];
    }

    private static function photo(Asset $asset): ?string
    {
        $p = $asset->photo_path;
        $disk = Storage::disk('public');
        if (! $p || ! $disk->exists($p)) return null;
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) return null;      // DomPDF tidak membaca webp
        $bin = $disk->get($p);
        if (strlen($bin) > 4 * 1024 * 1024) return null;
        return 'data:image/' . ($ext === 'jpg' ? 'jpeg' : $ext) . ';base64,' . base64_encode($bin);
    }

    public static function build(AssetAssignment $a, bool $form = false): array
    {
        $blank = $form ? str_repeat('.', 28) : '';
        $asset = $a->asset;
        $type = $a->type;
        $it = self::it($a, $blank);

        // Penyerah / penerima: serah terima = IT -> pengguna, pengembalian = pengguna -> IT, mutasi = pemegang lama -> pemegang baru.
        $giver = $type === 'checkout' ? $it : self::party($a->from, $form ? $blank : '');
        $taker = $type === 'checkin' ? $it : self::party($a->to, $form ? $blank : '');
        if ($type === 'checkout' && ! $form && ! $a->creator) $giver = self::party(null, '');
        $user = $type === 'checkin' ? $giver : $taker;            // pengguna (karyawan) pada transaksi ini

        $label = [
            'company' => self::cut(strtoupper((string) config('labels.company')), 32),
            'tag' => self::cut(strtoupper((string) $asset->asset_tag), 17),
            'serial' => self::cut(strtoupper($asset->labelSerialText()), 26),
            'qr' => 'data:image/svg+xml;base64,' . base64_encode(QrCode::format('svg')->size(300)->margin(0)->errorCorrection('M')->generate((string) $asset->asset_tag)),
        ];

        // Kelengkapan = komponen terpasang pada saat BAST ini (atau saat kelengkapannya terakhir diperbarui), terhubung ke data pembelian
        // (PO / harga) dan dokumen pemasangannya. Baris dibatasi agar BAST tetap satu halaman; sisanya dicatat sebagai "+N komponen lainnya".
        $asOf = $form ? null : $a->equipmentAsOf();
        $detail = $asset->installedComponentsDetailed($asOf);
        $extras = [];
        $overflow = $detail->count() > self::MAX_EQUIPMENT_ROWS;
        $shown = $overflow ? self::MAX_EQUIPMENT_ROWS - 1 : $detail->count();          // bila meluap: satu baris dipakai untuk ringkasan "+N lainnya"
        foreach ($detail->take($shown) as $r) {
            $c = $r['component'];
            $extras[] = [
                'name' => self::equipmentName($c, (int) $r['qty']),
                'po' => self::cut($r['po'] ?: '-', 22),
                'cost' => $r['cost'] > 0 ? self::cut('Rp ' . number_format($r['cost'] * max(1, (int) $r['qty']), 0, ',', '.'), 18) : '-',
                'status' => 'Terpasang' . ($r['installed_at'] ? ' ' . $r['installed_at']->format('d-m-Y') : ''),
                'ref' => $r['doc']?->bast_number,
            ];
        }
        if ($overflow) $extras[] = ['name' => '+ ' . ($detail->count() - $shown) . ' komponen lainnya (lihat halaman aset)', 'po' => '-', 'cost' => '-', 'status' => '', 'ref' => null];
        $refs = $detail->pluck('doc.bast_number')->filter()->unique()->values();
        $extrasNote = collect([
            (! $form && $a->equipment_updated_at) ? 'Kelengkapan diperbarui per ' . $a->equipment_updated_at->format('d-m-Y') . '.' : null,
            $refs->isNotEmpty() ? 'Dokumen pemasangan: ' . $refs->take(2)->implode(', ') . ($refs->count() > 2 ? ' dan ' . ($refs->count() - 2) . ' lainnya' : '') . '.' : null,
        ])->filter()->implode(' ') ?: null;

        $price = (float) $asset->purchase_price;
        return [
            'title' => self::TITLES[$type] ?? self::TITLES['checkout'],
            'company' => self::cut(self::setting('bast_company'), 50),
            'number' => $a->bast_number,
            'date' => $a->assigned_at?->format('d-m-Y'),
            'revision' => self::setting('bast_revision'),
            'status' => self::setting('bast_status'),
            'giver' => $giver, 'taker' => $taker, 'user' => $user,
            'asset' => [
                'name' => self::cut($asset->asset_tag, 18), 'category' => self::cut($asset->category?->name ?: '-', 14), 'po' => self::cut($asset->po_pr_no ?: '-', 17),
                'manufacturer' => self::cut($asset->brand ?: '-', 13), 'model' => self::cut($asset->model ?: '-', 17),
                'cost' => $price > 0 ? self::cut('Rp ' . number_format($price, 0, ',', '.'), 16) : '-',
                'condition' => Asset::CONDITIONS[$a->condition] ?? $a->condition,
                'tag' => self::cut($asset->asset_tag, 30), 'serial' => self::cut($asset->serial_number ?: '-', 30),
            ],
            'photo' => self::photo($asset),
            'label' => $label,
            'extras' => $extras, 'extrasNote' => $extrasNote, 'compact' => ! empty($extras),
            'statements' => self::STATEMENTS,
            // kolom tanda tangan: serah terima & mutasi = IT menyerahkan; pengembalian = pengguna menyerahkan
            'sig' => [
                'giver_role' => $type === 'checkin' ? 'Pengguna' : 'IT', 'taker_role' => $type === 'checkin' ? 'IT' : 'Pengguna',
                'giver_name' => self::cut($type === 'checkin' ? $giver['name'] : $it['name'], 30),
                'taker_name' => self::cut($taker['name'], 30),
            ],
            'approvers' => [
                ['Menyetujui', 'Superintendent', self::cut(self::setting('bast_superintendent'), 26)],
                ['Mengetahui', 'Manager', self::cut(self::setting('bast_manager'), 26)],
                ['Mengetahui', 'Manager Ekspat', self::cut(self::setting('bast_manager_ekspat'), 26)],
                ['Mengetahui', 'GM', self::cut(self::setting('bast_gm'), 26)],
                ['Mengetahui', 'GM Ekspat', self::cut(self::setting('bast_gm_ekspat'), 26)],
            ],
        ];
    }
}
