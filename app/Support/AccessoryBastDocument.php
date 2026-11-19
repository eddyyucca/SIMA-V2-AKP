<?php

namespace App\Support;

use App\Models\AccessoryHandover;
use App\Models\Employee;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Menyusun dan membuat PDF bukti serah terima / pengeluaran / pengembalian barang pendukung IT (accessories, consumable, komponen).
 * A4, satu halaman, kolom tanda tangan dikosongkan untuk tanda tangan basah. Judul dan kalimat pernyataan menyesuaikan jenis barang.
 */
class AccessoryBastDocument
{
    /** Kalimat pernyataan per jenis (checkout) dan untuk pengembalian. */
    public const STATEMENTS = [
        'checkout' => [
            'consumable' => 'Barang di atas telah diterima dalam keadaan baik dan jumlah yang sesuai. Penerima bertanggung jawab atas penggunaan barang sesuai keperluan pekerjaan.',
            'accessory' => 'Accessories di atas telah diterima dalam keadaan baik dan jumlah yang sesuai. Penerima bertanggung jawab atas penggunaan, pemeliharaan, dan keamanan accessories, serta wajib mengembalikannya kepada IT saat resign, mutasi, atau penggantian barang.',
            'component' => 'Komponen di atas telah diterima dalam keadaan baik dan jumlah yang sesuai. Penerima bertanggung jawab atas penggunaan komponen sesuai keperluan pekerjaan dan wajib melaporkan kerusakan atau kehilangan kepada IT maksimal 1 x 24 jam sejak kejadian.',
            'mixed' => 'Barang di atas telah diterima dalam keadaan baik dan jumlah yang sesuai. Penerima bertanggung jawab atas penggunaan barang sesuai keperluan pekerjaan; accessories dan komponen yang masih dipakai wajib dikembalikan kepada IT saat resign, mutasi, atau penggantian barang.',
        ],
        'install' => [
            'component' => 'Komponen di atas telah dipasang pada aset tersebut dan diuji berfungsi dengan baik. Komponen yang dilepas diserahkan kembali kepada IT (yang rusak dihapus dari stok). Pemegang aset bertanggung jawab atas aset beserta komponen yang terpasang.',
            'mixed' => 'Komponen di atas telah dipasang pada aset tersebut dan diuji berfungsi dengan baik.',
        ],
        'return' => [
            'accessory' => 'Accessories di atas telah dikembalikan oleh karyawan dan diterima kembali oleh IT sesuai jumlah dan kondisi yang tercantum. Kerusakan akibat kelalaian menjadi tanggung jawab karyawan sesuai kebijakan perusahaan.',
            'mixed' => 'Barang di atas telah dikembalikan oleh karyawan dan diterima kembali oleh IT sesuai jumlah dan kondisi yang tercantum.',
        ],
    ];

    private static function cut(?string $s, int $max): string { return mb_strimwidth((string) $s, 0, $max, '…'); }

    private static function party(?Employee $e, ?User $fallbackUser = null, bool $dept = true): array
    {
        $e ??= $fallbackUser?->employee;
        $name = self::cut($e?->name ?: ($fallbackUser?->name ?: '-'), 60);
        $nik = self::cut($e?->nik ?: '-', 40);
        $position = self::cut($e?->position ?: ($fallbackUser ? 'Staff IT' : '-'), 60);
        $deptName = $dept ? self::cut($e?->department?->name ?: '-', 60) : null;
        return [
            'name' => $name, 'nik' => $nik, 'position' => $position, 'dept' => $deptName,
            'upName' => $dept,        // sisi karyawan ditulis kapital, sisi IT apa adanya (mengikuti contoh formulir)
            'rows' => array_values(array_filter([['Nama', $name, $dept], ['NIK', $nik, false], ['Jabatan', $position, true], $dept ? ['Department', $deptName, true] : null])),
        ];
    }

    /**
     * Penerima untuk tujuan non-karyawan: baris tujuan (Department / Lokasi / Perangkat) lalu PIC bila dipilih.
     * Nama penanda tangan = PIC; bila tanpa PIC, ditulis tangan saat tanda tangan.
     */
    private static function destination(AccessoryHandover $h): array
    {
        $pic = $h->pic?->loadMissing('department');
        $rows = match ($h->target_type) {
            'department' => [['Department', self::cut($h->department?->name ?: '-', 60), true]],
            'location' => [['Lokasi', self::cut($h->location?->name ?: '-', 60), true]],
            'asset' => [['Perangkat', self::cut(trim(($h->asset?->asset_tag ?? '-') . ' — ' . ($h->asset?->brand ?? '') . ' ' . ($h->asset?->model ?? '')), 70), true]],
            default => [],
        };
        if ($pic) {
            $rows[] = ['PIC / Penerima', self::cut($pic->name, 60), true];
            $rows[] = ['NIK', self::cut($pic->nik ?: '-', 40), false];
            $rows[] = ['Jabatan', self::cut($pic->position ?: '-', 60), true];
        }
        return ['name' => $pic ? self::cut($pic->name, 60) : '', 'nik' => $pic?->nik ?: '-', 'position' => $pic?->position ?: '-', 'dept' => null, 'upName' => true, 'rows' => $rows];
    }

    /** "PT. ADHI KARTIKO PRATAMA TBK" -> "PT. Adhi Kartiko Pratama Tbk" untuk kalimat kaki dokumen. */
    private static function companyName(string $company): string
    {
        return preg_replace('/\bPt\./u', 'PT.', mb_convert_case(mb_strtolower($company), MB_CASE_TITLE));
    }

    /** Catatan tiap baris: merek/model (accessories), nomor seri (komponen), kondisi saat kembali. */
    private static function remark($i): string
    {
        $it = $i->item();
        $parts = [];
        if ($i->kind() === 'accessory' && $it?->brand_model) $parts[] = $it->brand_model;
        if ($i->kind() === 'component' && $it?->serial_number) $parts[] = 'SN ' . $it->serial_number;
        if ($i->kind() === 'consumable' && $it?->unit) $parts[] = 'Satuan: ' . $it->unit;
        if ($i->condition) $parts[] = 'Kondisi: ' . (\App\Models\Asset::CONDITIONS[$i->condition] ?? $i->condition);
        return self::cut(implode(' · ', $parts), 80) ?: '-';
    }

    public static function build(AccessoryHandover $h): array
    {
        if ($h->type === 'install') return self::buildInstall($h);
        $h->loadMissing(['items.stockable', 'employee.department', 'creator.employee.department', 'request']);
        $h->loadMissing(['department', 'location', 'asset', 'pic']);
        $emp = in_array($h->target_type, ['department', 'location', 'asset'], true) ? self::destination($h) : self::party($h->employee);
        $it = self::party($h->creator?->employee, $h->creator, false);
        $checkout = $h->type === 'checkout';
        $kind = $h->kind();
        $company = BastDocument::setting('bast_company');

        $rows = $h->items->map(function ($i) {
            $item = $i->item();
            return [
                'kind' => $i->kindLabel(), 'name' => self::cut($item?->name, 60), 'code' => $item?->code ?? '-',
                'category' => self::cut($item?->category?->name ?: '-', 40), 'qty' => (string) $i->qty, 'remark' => self::remark($i),   // Qty hanya angka; satuan ada di Catatan
            ];
        })->all();

        return [
            'company' => self::cut(strtoupper($company), 50),
            'companyName' => self::companyName($company),
            'title' => $h->title(),
            'number' => $h->bast_number, 'date' => $h->handed_at->format('d-m-Y'),
            'status' => BastDocument::setting('bast_status'),
            'ticket' => $h->request?->ticket_number ?: '-',
            'giverLabel' => 'DATA PENYERAH', 'receiverLabel' => 'DATA PENERIMA',
            'giver' => $checkout ? $it : $emp, 'receiver' => $checkout ? $emp : $it,
            'blocks' => [['label' => 'DATA PENYERAH', 'rows' => ($checkout ? $it : $emp)['rows']], ['label' => 'DATA PENERIMA', 'rows' => ($checkout ? $emp : $it)['rows']]],
            'tableTitle' => 'DETAIL BARANG', 'specChanges' => [],
            'rows' => $rows, 'total' => $h->items->sum('qty'), 'totalLabel' => 'TOTAL',
            'statement' => self::STATEMENTS[$h->type][$kind] ?? self::STATEMENTS[$h->type]['mixed'],
            'notes' => self::cut($h->notes, 300),
            'sign' => [
                $checkout ? 'Diserahkan (IT)' : 'Dikembalikan',
                $checkout ? 'Diterima' : 'Diterima (IT)',
                'Mengetahui',
            ],
            'known' => self::cut(BastDocument::setting('bast_acc_known'), 40),
            'logo' => Branding::logoDataUri(),
        ];
    }

    /**
     * Dokumen pemasangan komponen: pelaksana (IT), data aset, pemegang / peminta, daftar komponen dipasang & dilepas,
     * dan perubahan spesifikasi (sebelum -> sesudah). Aset tanpa pemegang tercatat sebagai stok gudang IT.
     */
    private static function buildInstall(AccessoryHandover $h): array
    {
        $h->loadMissing(['items.stockable.category', 'employee.department', 'creator.employee.department', 'request', 'asset.category', 'asset.location', 'pic.department']);
        $it = self::party($h->creator?->employee, $h->creator, false);
        $a = $h->asset;
        $holder = $h->employee ? self::party($h->employee) : null;
        $pic = $h->pic ? self::party($h->pic) : null;
        $company = BastDocument::setting('bast_company');
        $status = $a ? (\App\Models\Asset::STATUSES[$a->status] ?? $a->status) : '-';

        // baris dipadatkan agar dokumen tetap satu halaman A4 walau ada beberapa komponen, perubahan spesifikasi, dan catatan
        $assetRows = [
            ['Kode aset', self::cut(($a?->asset_tag ?: '-') . ' — ' . (trim(($a?->brand ?? '') . ' ' . ($a?->model ?? '')) ?: '-'), 90), true],
            ['Serial number', self::cut($a?->serial_number ?: '-', 50), false],
            ['Kategori / Lokasi', self::cut(($a?->category?->name ?: '-') . ' / ' . ($a?->location?->name ?: '-'), 90), true],
        ];
        $holderRows = $holder
            ? [['Pemegang aset', self::cut(implode(' · ', array_filter([$holder['name'], $holder['nik'], $holder['position'], $holder['dept']])), 100), true]]
            : [['Pemegang aset', 'Belum ada pemegang (stok gudang IT)', false]];
        if ($pic) $holderRows[] = ['Peminta', self::cut($pic['name'] . ' · ' . $pic['nik'] . ' · ' . $pic['position'], 100), true];
        $holderRows[] = ['Alasan', $h->reason ?: '-', false];

        $rows = $h->items->map(function ($i) {
            $c = $i->item();
            $remove = $i->action === 'remove';
            $act = $remove ? ($i->condition === 'major_damage' ? 'Dilepas — rusak (dihapus dari stok)' : 'Dilepas — baik (kembali ke stok)') : 'Dipasang';
            return ['kind' => $i->kindLabel(), 'name' => self::cut($c?->name, 60), 'code' => $c?->code ?? '-', 'category' => self::cut($c?->category?->name ?: '-', 40),
                'qty' => (string) $i->qty, 'remark' => self::cut($act . ($c?->serial_number ? ' · SN ' . $c->serial_number : ''), 90)];
        })->all();
        $installed = $h->items->where('action', 'install')->sum('qty');

        return [
            'company' => self::cut(strtoupper($company), 50), 'companyName' => self::companyName($company),
            'title' => $h->title(), 'number' => $h->bast_number, 'date' => $h->handed_at->format('d-m-Y'),
            'status' => BastDocument::setting('bast_status'), 'ticket' => $h->request?->ticket_number ?: '-',
            'giverLabel' => 'DATA PELAKSANA (IT)', 'receiverLabel' => 'DATA PEMEGANG ASET',
            'giver' => $it, 'receiver' => $holder ?? ['name' => '', 'nik' => '-', 'position' => '-', 'dept' => null, 'upName' => true, 'rows' => []],
            'blocks' => [
                ['label' => 'DATA PELAKSANA (IT)', 'rows' => $it['rows']],
                ['label' => 'DATA ASET (' . $status . ')', 'rows' => $assetRows],
                ['label' => 'DATA PEMEGANG / PEMINTA', 'rows' => $holderRows],
            ],
            'compact' => true, 'tableTitle' => 'DETAIL KOMPONEN', 'rows' => $rows, 'total' => $installed, 'totalLabel' => 'TOTAL DIPASANG',
            'specChanges' => array_map(fn ($c) => ['label' => $c['label'], 'from' => $c['from'] ?: '-', 'to' => $c['to']], $h->meta['spec_changes'] ?? []),
            'statement' => self::STATEMENTS['install']['component'],
            'notes' => self::cut($h->notes, 200),
            'sign' => ['Dikerjakan (IT)', 'Diketahui Pemegang Aset', 'Mengetahui'],
            'known' => self::cut(BastDocument::setting('bast_acc_known'), 40), 'logo' => Branding::logoDataUri(),
        ];
    }

    public static function pdf(AccessoryHandover $h)
    {
        return Pdf::loadView('pdf.accessory-bast', ['d' => self::build($h)])->setPaper('a4');
    }
}
