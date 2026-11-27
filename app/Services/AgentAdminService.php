<?php

namespace App\Services;

use App\Models\AgentAnnouncement;
use App\Models\AgentChatMessage;
use App\Models\AgentChatSession;
use App\Models\Asset;
use App\Models\AssetAgentCommand;
use App\Models\AssetAgentToken;
use App\Models\AssetHelperToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sisi admin SIMA Asset Agent (token, perintah jarak jauh, chat, pengumuman). Aturannya sama dengan SIMA lama;
 * UI Filament hanya memanggil service ini. Pengecekan izin dilakukan pemanggil (manage inventory / Admin).
 */
class AgentAdminService
{
    /** Token baru; token aktif sebelumnya dicabut. Plaintext hanya dikembalikan di sini (tampilkan sekali). */
    public function issueToken(Asset $asset, ?User $by): string
    {
        AssetAgentToken::revokeActive($asset);
        [, $plain] = AssetAgentToken::issueFor($asset, $by?->id);
        return $plain;
    }

    public function revokeToken(Asset $asset): void
    {
        AssetAgentToken::revokeActive($asset);
    }

    /** Hapus perangkat dari monitor (agent terpasang di laptop yang salah). Aset dan datanya tidak disentuh. */
    public function purge(Asset $asset): void
    {
        DB::transaction(function () use ($asset) {
            AssetAgentToken::revokeActive($asset);
            AssetHelperToken::where('asset_id', $asset->id)->delete();
            $asset->agentCommands()->active()->update(['status' => 'cancelled', 'finished_at' => now()]);
            $asset->agentHeartbeat()->delete();
            $asset->specChanges()->delete();
            Asset::whereKey($asset->id)->update(['agent_status' => 'never_reported', 'agent_last_seen' => null]);
        });
    }

    /**
     * Antrekan perintah untuk agent. Hanya jenis dari daftar tetap. Restart / batal restart hanya Admin.
     * @return array{ok:bool, level:string, message:string}
     */
    public function queueCommand(Asset $asset, string $type, array $data, ?User $by): array
    {
        if (! in_array($type, AssetAgentCommand::TYPES, true)) throw new InvalidArgumentException('Jenis perintah tidak dikenal.');
        if (in_array($type, AssetAgentCommand::ADMIN_ONLY_TYPES, true) && ! $by?->hasRole('Admin')) {
            return ['ok' => false, 'level' => 'danger', 'message' => 'Restart dan pembatalannya hanya boleh dilakukan Admin.'];
        }
        $clean = fn (string $v) => trim(preg_replace('/\s{2,}/u', ' ', preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v)));
        $active = $asset->agentCommands()->active();

        if ((clone $active)->count() >= AssetAgentCommand::MAX_ACTIVE_PER_ASSET) {
            return ['ok' => false, 'level' => 'danger', 'message' => 'Terlalu banyak perintah menunggu (maks. ' . AssetAgentCommand::MAX_ACTIVE_PER_ASSET . '). Tunggu atau batalkan dulu.'];
        }
        if ($type !== 'notify_user' && (clone $active)->where('type', $type)->exists()) {
            return ['ok' => false, 'level' => 'warning', 'message' => 'Perintah jenis ini sudah menunggu untuk perangkat ini.'];
        }

        $params = null;
        if ($type === 'restart_computer') {
            $delay = (int) ($data['delay_minutes'] ?? 0);
            if ($delay < AssetAgentCommand::RESTART_MIN_MINUTES || $delay > AssetAgentCommand::RESTART_MAX_MINUTES) {
                return ['ok' => false, 'level' => 'danger', 'message' => 'Jeda restart harus ' . AssetAgentCommand::RESTART_MIN_MINUTES . '-' . AssetAgentCommand::RESTART_MAX_MINUTES . ' menit.'];
            }
            if (empty($data['confirm'])) return ['ok' => false, 'level' => 'danger', 'message' => 'Centang konfirmasi restart.'];
            $note = $clean((string) ($data['message'] ?? ''));
            if (mb_strlen($note) > AssetAgentCommand::RESTART_MESSAGE_MAX) {
                return ['ok' => false, 'level' => 'danger', 'message' => 'Pesan restart terlalu panjang (maks. ' . AssetAgentCommand::RESTART_MESSAGE_MAX . ' karakter).'];
            }
            $params = ['delay_minutes' => $delay, 'message' => $note];
        }
        if ($type === 'notify_user') {
            $message = $clean((string) ($data['message'] ?? ''));
            if ($message === '') return ['ok' => false, 'level' => 'danger', 'message' => 'Pesan wajib diisi.'];
            if (mb_strlen($message) > AssetAgentCommand::MESSAGE_MAX) return ['ok' => false, 'level' => 'danger', 'message' => 'Pesan terlalu panjang (maks. ' . AssetAgentCommand::MESSAGE_MAX . ' karakter).'];
            $params = ['message' => $message];
        }

        $asset->agentCommands()->create([
            'type' => $type, 'params' => $params, 'status' => 'pending', 'requested_by' => $by?->id,
            'requested_at' => now(), 'expires_at' => now()->addMinutes(AssetAgentCommand::ttlMinutesFor($type)),
        ]);

        return ['ok' => true, 'level' => 'success', 'message' => 'Perintah masuk antrean; dijalankan agent pada laporan berikutnya (maks. ±5 menit).'];
    }

    public function cancelCommand(Asset $asset, AssetAgentCommand $command): bool
    {
        if ($command->asset_id !== $asset->id || ! $command->isActive()) return false;
        $command->update(['status' => 'cancelled', 'finished_at' => now()]);
        return true;
    }

    // ---- chat ----

    /** @return array{ok:bool, message:string} */
    public function startChat(Asset $asset, ?User $by): array
    {
        $open = $asset->chatSessions()->open()->first();
        if ($open && ! $open->closeIfIdle()) return ['ok' => false, 'message' => 'Sudah ada sesi chat yang terbuka untuk perangkat ini.'];
        if (! $asset->agentTokens()->whereNull('revoked_at')->exists()) return ['ok' => false, 'message' => 'Perangkat ini belum memakai agent (belum ada token aktif).'];

        $asset->chatSessions()->create(['started_by' => $by?->id, 'status' => 'open', 'started_at' => now(), 'last_activity_at' => now()]);
        return ['ok' => true, 'message' => 'Sesi chat dimulai. Pengguna menerimanya pada laporan agent berikutnya.'];
    }

    /** @return array{ok:bool, message:string} */
    public function sendChat(AgentChatSession $session, string $body, ?User $by): array
    {
        $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body));
        if ($body === '') return ['ok' => false, 'message' => 'Pesan kosong.'];
        if (mb_strlen($body) > AgentChatSession::MESSAGE_MAX) return ['ok' => false, 'message' => 'Pesan terlalu panjang (maks. ' . AgentChatSession::MESSAGE_MAX . ' karakter).'];
        if ($session->closeIfIdle() || ! $session->isOpen()) return ['ok' => false, 'message' => 'Sesi chat sudah ditutup.'];

        AgentChatMessage::create(['chat_session_id' => $session->id, 'sender' => 'it', 'user_id' => $by?->id, 'body' => $body, 'created_at' => now()]);
        $session->update(['last_activity_at' => now()]);
        return ['ok' => true, 'message' => 'Terkirim.'];
    }

    public function closeChat(AgentChatSession $session, ?User $by): void
    {
        if ($session->isOpen()) $session->update(['status' => 'closed', 'closed_by' => $by?->id, 'closed_at' => now(), 'close_reason' => 'admin']);
    }

    // ---- pengumuman ----

    /** Perangkat yang memakai agent (token aktif). */
    public function agentDevices()
    {
        return Asset::query()->whereHas('agentTokens', fn ($t) => $t->whereNull('revoked_at'));
    }

    /** @param array{title:string, body:string, severity:string, require_ack?:bool, target_mode:string, asset_ids?:array, starts_at?:?string, ends_at?:?string} $d */
    public function createAnnouncement(array $d, ?User $by): AgentAnnouncement
    {
        if (! in_array($d['severity'], AgentAnnouncement::SEVERITIES, true) || ! in_array($d['target_mode'], AgentAnnouncement::TARGET_MODES, true)) {
            throw new InvalidArgumentException('Tingkat atau target pengumuman tidak valid.');
        }
        $ids = [];
        if ($d['target_mode'] === 'selected') {
            $ids = $this->agentDevices()->whereIn('assets.id', $d['asset_ids'] ?? [])->pluck('assets.id')->all();
            if ($ids === []) throw new InvalidArgumentException('Pilih minimal satu perangkat yang memakai agent.');
        }

        $announcement = DB::transaction(function () use ($d, $ids, $by) {
            $a = AgentAnnouncement::create([
                'title' => trim($d['title']), 'body' => trim($d['body']), 'severity' => $d['severity'],
                'require_ack' => (bool) ($d['require_ack'] ?? false), 'target_mode' => $d['target_mode'],
                'starts_at' => ! empty($d['starts_at']) ? Carbon::parse($d['starts_at']) : null,
                'ends_at' => ! empty($d['ends_at']) ? Carbon::parse($d['ends_at']) : null,
                'created_by' => $by?->id,
            ]);
            if ($ids !== []) $a->targets()->createMany(array_map(fn ($id) => ['asset_id' => $id], $ids));
            return $a;
        });
        $count = $d['target_mode'] === 'selected' ? count($ids) : $this->agentDevices()->count();
        try { app(NotificationService::class)->announcementPublished($announcement, $count, $by); } catch (\Throwable $e) { report($e); }

        return $announcement;
    }

    public function stopAnnouncement(AgentAnnouncement $a): void
    {
        if (in_array($a->state(), ['live', 'scheduled'], true)) $a->update(['ended_at' => now()]);
    }

    public function deleteAnnouncement(AgentAnnouncement $a): void
    {
        DB::transaction(function () use ($a) {
            $a->targets()->delete();
            $a->deliveries()->delete();
            $a->delete();
        });
    }
}
