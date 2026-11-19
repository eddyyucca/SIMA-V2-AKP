<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentAnnouncement;
use App\Models\AgentAnnouncementDelivery;
use App\Models\AgentChatMessage;
use App\Models\AgentChatSession;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API untuk SIMA Agent Helper (program di sesi pengguna): mengambil pengumuman dan pesan chat, melaporkan pengumuman
 * sudah tampil/dikonfirmasi, dan mengirim balasan chat. Dibatasi token khusus (AssetHelperTokenAuth). Pengguna TIDAK bisa
 * memulai atau menutup chat; itu hanya dari web oleh IT.
 */
class AgentHelperController extends Controller
{
    private const IDLE_POLL_SECONDS = 20;
    private const CHAT_POLL_SECONDS = 3;
    private const MAX_ANNOUNCEMENTS_PER_POLL = 5;

    private const SEVERITY_WEIGHT = ['urgent' => 0, 'important' => 1, 'info' => 2];

    public function poll(Request $request): JsonResponse
    {
        /** @var Asset $asset */
        $asset = $request->attributes->get('helper_asset');

        $chat = $this->chatState($asset, (int) $request->query('chat_session', 0), (int) $request->query('after', 0));

        return response()->json([
            'poll_seconds' => ($chat['session']['status'] ?? null) === 'open' ? self::CHAT_POLL_SECONDS : self::IDLE_POLL_SECONDS,
            'announcements' => $this->pendingAnnouncements($asset),
            'chat' => $chat,
        ]);
    }

    public function shown(Request $request, int $id): JsonResponse
    {
        $announcement = $this->announcementFor($request, $id);
        $this->delivery($announcement, $request->attributes->get('helper_asset'))
            ->forceFill(['shown_at' => now()])->save();

        return response()->json(['status' => 'ok']);
    }

    public function ack(Request $request, int $id): JsonResponse
    {
        $announcement = $this->announcementFor($request, $id);
        $delivery = $this->delivery($announcement, $request->attributes->get('helper_asset'));
        $delivery->forceFill(['shown_at' => $delivery->shown_at ?? now(), 'acked_at' => $delivery->acked_at ?? now()])->save();

        return response()->json(['status' => 'ok']);
    }

    public function chatSend(Request $request): JsonResponse
    {
        /** @var Asset $asset */
        $asset = $request->attributes->get('helper_asset');

        $data = $request->validate([
            'session_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'max:'.AgentChatSession::MESSAGE_MAX],
        ]);

        $session = AgentChatSession::where('asset_id', $asset->id)->find($data['session_id']);
        if (! $session || $session->closeIfIdle() || ! $session->isOpen()) {
            return response()->json(['status' => 'error', 'messages' => ['session' => ['closed']]], 409);
        }

        $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data['body']));
        if ($body === '') {
            return response()->json(['status' => 'error', 'messages' => ['body' => ['empty']]], 422);
        }

        $message = AgentChatMessage::create([
            'chat_session_id' => $session->id, 'sender' => 'user', 'body' => $body, 'created_at' => now(),
        ]);
        $session->update(['last_activity_at' => now()]);

        return response()->json(['status' => 'ok', 'message' => $this->messageArray($message)]);
    }

    // ---------- pengumuman ----------

    private function targeted(Asset $asset)
    {
        return AgentAnnouncement::query()->where(function ($q) use ($asset) {
            $q->where('target_mode', 'all')
                ->orWhereHas('targets', fn ($t) => $t->where('asset_id', $asset->id));
        });
    }

    private function announcementFor(Request $request, int $id): AgentAnnouncement
    {
        $announcement = $this->targeted($request->attributes->get('helper_asset'))->find($id);
        abort_unless($announcement, 404);

        return $announcement;
    }

    private function delivery(AgentAnnouncement $announcement, Asset $asset): AgentAnnouncementDelivery
    {
        return AgentAnnouncementDelivery::firstOrCreate(['announcement_id' => $announcement->id, 'asset_id' => $asset->id]);
    }

    private function pendingAnnouncements(Asset $asset): array
    {
        $live = $this->targeted($asset)->live()->get();
        if ($live->isEmpty()) {
            return [];
        }

        $deliveries = AgentAnnouncementDelivery::where('asset_id', $asset->id)
            ->whereIn('announcement_id', $live->pluck('id'))->get()->keyBy('announcement_id');

        return $live
            ->filter(function (AgentAnnouncement $a) use ($deliveries) {
                $d = $deliveries->get($a->id);
                if ($a->require_ack) {
                    return ! $d || ! $d->acked_at;     // terus muncul sampai dikonfirmasi
                }

                return ! $d || ! $d->shown_at;          // cukup sekali tampil
            })
            ->sortBy(fn ($a) => [self::SEVERITY_WEIGHT[$a->severity] ?? 3, $a->id])
            ->take(self::MAX_ANNOUNCEMENTS_PER_POLL)
            ->map(fn (AgentAnnouncement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'body' => $a->body,
                'severity' => $a->severity,
                'require_ack' => $a->require_ack,
                'created_at' => $a->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    // ---------- chat ----------

    /** @return array{session: ?array<string, mixed>, messages: list<array<string, mixed>>} */
    private function chatState(Asset $asset, int $knownSessionId, int $afterMessageId): array
    {
        $open = AgentChatSession::where('asset_id', $asset->id)->open()->orderByDesc('id')->first();
        if ($open && $open->closeIfIdle()) {
            $open = null;
        }

        if ($open) {
            $after = $open->id === $knownSessionId ? $afterMessageId : 0;

            return [
                'session' => [
                    'id' => $open->id,
                    'status' => 'open',
                    'started_at' => $open->started_at?->toIso8601String(),
                    'started_by' => $open->starter?->display_name ?: trim(($open->starter?->first_name ?? '').' '.($open->starter?->last_name ?? '')),
                ],
                'messages' => $this->messagesAfter($open, $after),
            ];
        }

        // Jendela masih memegang sesi yang sudah ditutup (oleh IT atau karena idle): beri tahu sekali.
        if ($knownSessionId > 0) {
            $closed = AgentChatSession::where('asset_id', $asset->id)->find($knownSessionId);
            if ($closed) {
                return [
                    'session' => ['id' => $closed->id, 'status' => 'closed', 'close_reason' => $closed->close_reason],
                    'messages' => $this->messagesAfter($closed, $afterMessageId),
                ];
            }
        }

        return ['session' => null, 'messages' => []];
    }

    private function messagesAfter(AgentChatSession $session, int $after): array
    {
        return $session->messages()->with('author')->where('id', '>', $after)->limit(100)->get()
            ->map(fn (AgentChatMessage $m) => $this->messageArray($m))->all();
    }

    private function messageArray(AgentChatMessage $m): array
    {
        return [
            'id' => $m->id,
            'sender' => $m->sender,
            'name' => $m->sender === 'it'
                ? ($m->author?->display_name ?: trim(($m->author?->first_name ?? '').' '.($m->author?->last_name ?? '')) ?: 'IT')
                : null,
            'body' => $m->body,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
