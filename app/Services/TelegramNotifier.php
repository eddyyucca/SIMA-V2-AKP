<?php

namespace App\Services;

use App\Models\ITRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Notifikasi Telegram ke satu grup bersama Tim IT (bukan per-admin) tiap ada request baru yang
// perlu approval - dipanggil sekali per event (bukan di dalam loop per-admin seperti WebPush),
// supaya grup tidak kebanjiran pesan duplikat. Gagal kirim (token belum diisi, API down, dll)
// sengaja tidak pernah melempar exception - notifikasi Telegram ini pelengkap, bukan syarat
// request berhasil disimpan.
class TelegramNotifier
{
    public static function notifyNewRequest(ITRequest $item): void
    {
        $botToken = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! $botToken || ! $chatId) {
            return;
        }

        $typeLabels = [
            'ITR' => 'IT Request',
            'TICKET_SISTEM' => 'Ticket Sistem',
            'BAK' => 'Berita Acara Kerusakan',
            'SURAT_JALAN' => 'Surat Jalan',
            'TT' => 'Tanda Terima',
            'WO' => 'Work Order',
            'PEMINJAMAN_ASET' => 'Peminjaman Aset',
        ];
        $typeLabel = $typeLabels[$item->type] ?? $item->type;

        $message = "🔔 <b>Request Baru Perlu Approval</b>\n\n"
            . '<b>Jenis:</b> ' . e($typeLabel) . "\n"
            . '<b>Requester:</b> ' . e($item->requester_name) . ($item->department ? ' (' . e($item->department) . ')' : '') . "\n"
            . '<b>Judul:</b> ' . e($item->title ?: '-') . "\n\n"
            . 'Cek &amp; proses di Dashboard: ' . route('dashboard');

        try {
            $response = Http::timeout(5)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);

            if ($response->failed()) {
                Log::warning('Notifikasi Telegram ditolak API: ' . $response->body());
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal mengirim notifikasi Telegram: ' . $e->getMessage());
        }
    }
}
