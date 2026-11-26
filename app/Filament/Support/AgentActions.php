<?php

namespace App\Filament\Support;

use App\Models\Asset;
use App\Models\AssetAgentCommand;
use App\Services\AgentAdminService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Aksi SIMA Asset Agent di halaman detail aset: token, perintah jarak jauh, chat, hapus dari monitor. Izin: manage inventory. */
class AgentActions
{
    private static function svc(): AgentAdminService { return app(AgentAdminService::class); }
    private static function allowed(): bool { return (bool) auth()->user()?->can('manage inventory'); }
    /** Unduh paket pasang agent (ZIP + token baru) supaya Support tidak perlu file server / flashdisk. */
    public static function downloadAction(?\Closure $asset = null): Action
    {
        // di tabel: record dari baris ($record diisi Filament); di halaman detail: dari closure $asset
        $resolve = fn ($record = null): Asset => $asset ? $asset() : $record;
        return Action::make('agent_download')->label('Unduh Agent')->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn () => self::allowed())
            ->modalHeading('Unduh paket pasang agent')
            ->modalDescription(function ($record = null) use ($resolve) {
                $a = $resolve($record);
                return 'Paket ZIP berisi agent terbaru + token khusus untuk ' . $a->asset_tag . '. Membuat paket membuat token BARU' .
                    ($a->agentTokens()->whereNull('revoked_at')->exists() ? ' dan MENCABUT token lama: agent yang sudah terpasang di laptop ini berhenti diterima sampai dipasang ulang dengan paket ini.' : '.') .
                    ' Di laptop tujuan: ekstrak, lalu jalankan Pasang-Agent.cmd.';
            })
            ->modalSubmitActionLabel('Buat & unduh')->requiresConfirmation()
            ->action(function ($record = null) use ($resolve) {
                try {
                    $pkg = app(\App\Services\AgentInstallerPackage::class)->build($resolve($record), auth()->user());
                } catch (\RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    return null;
                }
                return response()->download($pkg['path'], $pkg['filename'], ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
            });
    }

    /** @param \Closure():Asset $asset */
    public static function make(\Closure $asset): array
    {
        $hasToken = fn () => $asset()->agentTokens()->whereNull('revoked_at')->exists();
        $openChat = fn () => $asset()->chatSessions()->open()->first();

        return [
            self::downloadAction($asset),
            Action::make('agent_token')->label('Buat Token Agent')->icon(Heroicon::OutlinedKey)
                ->visible(fn () => self::allowed())
                ->modalHeading('Buat token agent')
                ->modalDescription('Token lama untuk aset ini dicabut. Token baru ditampilkan SEKALI: salin ke config.json agent atau installer.')
                ->requiresConfirmation()
                ->action(function () use ($asset) {
                    $plain = self::svc()->issueToken($asset(), auth()->user());
                    Notification::make()->title('Token agent dibuat. Salin sekarang, tidak akan ditampilkan lagi:')->body($plain)->success()->persistent()->send();
                }),
            Action::make('agent_revoke')->label('Cabut Token Agent')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                ->visible(fn () => self::allowed() && $hasToken())
                ->requiresConfirmation()->modalDescription('Agent di laptop ini berhenti diterima server (401) sampai token baru dibuat.')
                ->action(function () use ($asset) { self::svc()->revokeToken($asset()); Notification::make()->title('Token agent dicabut')->success()->send(); }),
            Action::make('agent_command')->label('Kirim Perintah')->icon(Heroicon::OutlinedCommandLine)
                ->visible(fn () => self::allowed() && $hasToken())
                ->modalHeading('Perintah untuk agent')
                ->modalDescription('Dijalankan agent pada laporan berikutnya (maks. ±5 menit) dan hanya saat laptop online. Setiap perintah tercatat atas nama Anda.')
                ->schema([
                    Select::make('type')->label('Perintah')->required()->live()
                        ->options(fn () => collect(AssetAgentCommand::TYPES)
                            ->reject(fn ($t) => in_array($t, AssetAgentCommand::ADMIN_ONLY_TYPES, true) && ! auth()->user()?->hasRole('Admin'))
                            ->mapWithKeys(fn ($t) => [$t => str($t)->replace('_', ' ')->title()->toString()])->all()),
                    Textarea::make('message')->label('Pesan')->rows(2)->maxLength(AssetAgentCommand::MESSAGE_MAX)
                        ->visible(fn ($get) => in_array($get('type'), ['notify_user', 'restart_computer'], true)),
                    TextInput::make('delay_minutes')->label('Jeda restart (menit)')->numeric()->default(10)
                        ->minValue(AssetAgentCommand::RESTART_MIN_MINUTES)->maxValue(AssetAgentCommand::RESTART_MAX_MINUTES)
                        ->visible(fn ($get) => $get('type') === 'restart_computer'),
                    Checkbox::make('confirm')->label('Saya paham pengguna akan kehilangan pekerjaan yang belum disimpan')
                        ->visible(fn ($get) => $get('type') === 'restart_computer'),
                ])
                ->action(function (array $data) use ($asset) {
                    $r = self::svc()->queueCommand($asset(), $data['type'], $data, auth()->user());
                    Notification::make()->title($r['message'])->{$r['ok'] ? 'success' : ($r['level'] === 'warning' ? 'warning' : 'danger')}()->send();
                }),
            Action::make('agent_chat_start')->label('Mulai Chat dengan Pengguna')->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->visible(fn () => self::allowed() && $hasToken() && ! $openChat())
                ->action(function () use ($asset) {
                    $r = self::svc()->startChat($asset(), auth()->user());
                    Notification::make()->title($r['message'])->{$r['ok'] ? 'success' : 'danger'}()->send();
                }),
            Action::make('agent_chat_send')->label('Balas Chat')->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn () => self::allowed() && (bool) $openChat())
                ->modalHeading('Chat dengan pengguna')
                ->modalDescription(function () use ($openChat) {
                    $s = $openChat();
                    if (! $s) return '';
                    return new \Illuminate\Support\HtmlString('<div style="max-height:260px;overflow:auto;font-size:13px">' . $s->messages()->latest('id')->limit(30)->get()->reverse()->map(
                        fn ($m) => '<p><b>' . ($m->sender === 'it' ? 'IT' : 'Pengguna') . ':</b> ' . e($m->body) . '</p>'
                    )->implode('') . '</div>');
                })
                ->schema([Textarea::make('body')->label('Pesan')->required()->rows(2)])
                ->action(function (array $data) use ($openChat) {
                    $s = $openChat();
                    $r = $s ? self::svc()->sendChat($s, $data['body'], auth()->user()) : ['ok' => false, 'message' => 'Tidak ada sesi chat terbuka.'];
                    Notification::make()->title($r['message'])->{$r['ok'] ? 'success' : 'danger'}()->send();
                }),
            Action::make('agent_chat_close')->label('Tutup Chat')->icon(Heroicon::OutlinedXMark)
                ->visible(fn () => self::allowed() && (bool) $openChat())
                ->requiresConfirmation()
                ->action(function () use ($openChat) { if ($s = $openChat()) self::svc()->closeChat($s, auth()->user()); Notification::make()->title('Sesi chat ditutup')->success()->send(); }),
            Action::make('agent_purge')->label('Hapus dari Monitor Agent')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->visible(fn () => self::allowed() && ($hasToken() || $asset()->agentHeartbeat()->exists()))
                ->requiresConfirmation()
                ->modalDescription('Token dicabut, laporan terakhir, riwayat spesifikasi, dan antrean perintah dihapus. Aset dan datanya tidak disentuh.')
                ->action(function () use ($asset) { self::svc()->purge($asset()); Notification::make()->title('Perangkat dihapus dari monitor agent')->success()->send(); }),
        ];
    }
}
