<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/** Mengubah error database mentah menjadi kalimat yang bisa dimengerti pengguna (detail teknis tetap masuk log). */
class FriendlyErrors
{
    /** @return array{0:string,1:string} [judul, isi] */
    public static function fromQuery(QueryException $e): array
    {
        $msg = $e->getMessage();
        $code = (int) ($e->errorInfo[1] ?? 0);
        // PostgreSQL: errorInfo[0] = SQLSTATE (errorInfo[1] hanya 7). Diterjemahkan ke kode MariaDB yang setara agar satu pemetaan pesan dipakai bersama.
        if ($code === 7 && is_string($state = $e->errorInfo[0] ?? null)) {
            $code = match ($state) {
                '23502' => 1048, '23505' => 1062, '23503' => (str_contains($msg, 'update or delete on table') ? 1451 : 1452),
                '22003' => 1264, '22P02', '22007', '22008' => 1366, '22001' => 1406, '40001', '40P01', '55P03' => 1213,
                default => $code,
            };
            if ($code === 1048 && preg_match('/column "(\w+)"/', $msg, $pg)) $msg = "Column '{$pg[1]}' cannot be null";
            if ($code === 1062 && preg_match('/Key \(([^)]*)\)=\(([^)]*)\)/', $msg, $pg)) $msg = "Duplicate entry '{$pg[2]}' for key '{$pg[1]}'";
        }
        $col = fn (string $raw) => Str::of($raw)->replace('_', ' ')->title()->toString();

        return match (true) {
            $code === 1048 && preg_match("/Column '(\w+)' cannot be null/", $msg, $m) === 1 => [
                'Ada isian wajib yang masih kosong',
                'Kolom "' . $col($m[1]) . '" belum diisi. Isi kolom tersebut (angka boleh 0), lalu simpan lagi.',
            ],
            $code === 1364 && preg_match("/Field '(\w+)' doesn't have a default value/", $msg, $m) === 1 => [
                'Ada isian wajib yang masih kosong',
                'Kolom "' . $col($m[1]) . '" belum diisi. Lengkapi lalu simpan lagi.',
            ],
            $code === 1062 => [
                'Data sudah ada',
                (preg_match("/Duplicate entry '([^']*)'/", $msg, $m) ? 'Nilai "' . $m[1] . '" sudah dipakai oleh data lain. ' : '') . 'Gunakan nilai yang berbeda.',
            ],
            $code === 1451 => ['Data tidak bisa dihapus', 'Data ini masih dipakai di data lain (misalnya riwayat, serah terima, atau stok). Lepaskan atau selesaikan keterkaitannya dulu.'],
            $code === 1452 => ['Data terkait tidak ditemukan', 'Pilihan yang Anda pakai sudah dihapus atau berubah. Muat ulang halaman, lalu pilih ulang datanya.'],
            in_array($code, [1264, 1690], true) => ['Angka terlalu besar atau kecil', 'Salah satu angka di luar batas yang diizinkan. Periksa jumlah dan harga, lalu simpan lagi.'],
            in_array($code, [1366, 1292, 1265], true) => ['Format isian tidak sesuai', 'Ada isian dengan format yang salah (misalnya huruf di kolom angka atau tanggal tidak valid). Periksa isian lalu simpan lagi.'],
            $code === 1406 => ['Isian terlalu panjang', 'Ada isian yang melebihi batas karakter. Persingkat isian tersebut lalu simpan lagi.'],
            in_array($code, [1205, 1213], true) => ['Sistem sedang sibuk', 'Data sedang diproses pengguna lain. Tunggu beberapa detik lalu coba lagi.'],
            default => ['Data belum bisa disimpan', 'Ada isian yang tidak sesuai aturan sistem. Periksa semua kolom lalu coba lagi. Bila masih gagal, hubungi admin dan sebutkan waktu kejadian ' . now()->format('d/m/Y H:i') . '.'],
        };
    }
}
