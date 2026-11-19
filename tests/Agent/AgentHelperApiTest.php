<?php

namespace Tests\Agent;

use App\Models\AgentAnnouncement;
use App\Models\AgentChatMessage;
use App\Models\AgentChatSession;
use App\Models\Asset;
use App\Models\AssetAgentToken;
use App\Models\AssetHelperToken;
use App\Models\User;
use Tests\AgentTestCase;

/** API SIMA Agent Helper: token khusus, pengumuman, dan chat (pengguna hanya membalas). */
class AgentHelperApiTest extends AgentTestCase
{
    private Asset $asset;
    private string $agentToken;
    private string $helperToken;

    protected function setUp(): void
    {
        parent::setUp();


        $this->asset = $this->makeAsset();
        [, $this->agentToken] = AssetAgentToken::issueFor($this->asset, null);
        [, $this->helperToken] = AssetHelperToken::rotateFor($this->asset);
    }

    private function poll(array $query = [], ?string $token = null)
    {
        return $this->withToken($token ?? $this->helperToken)->getJson('/api/helper/v1/poll'.($query ? '?'.http_build_query($query) : ''));
    }

    private function announcement(array $attrs = [], array $assetIds = []): AgentAnnouncement
    {
        $a = AgentAnnouncement::create($attrs + ['title' => 'Judul', 'body' => 'Isi', 'severity' => 'info', 'target_mode' => 'all']);
        foreach ($assetIds as $id) {
            $a->targets()->create(['asset_id' => $id]);
        }

        return $a;
    }

    private function openChat(?Asset $asset = null): AgentChatSession
    {
        return ($asset ?? $this->asset)->chatSessions()->create([
            'status' => 'open', 'started_at' => now(), 'last_activity_at' => now(),
        ]);
    }

    // ---------- token khusus ----------

    public function test_heartbeat_issues_a_helper_token_once_and_reissues_on_request(): void
    {
        $asset = $this->makeAsset();
        [, $agent] = AssetAgentToken::issueFor($asset, null);

        $first = $this->withToken($agent)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'X'])->assertOk()->json('helper');
        $this->assertSame(40, strlen($first['token']));
        $this->assertNotNull($first['expires_at']);

        $this->withToken($agent)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'X'])->assertOk()->assertJsonPath('helper', null);

        $again = $this->withToken($agent)->postJson('/api/agent/v1/heartbeat', ['helper_missing' => true])->assertOk()->json('helper');
        $this->assertNotSame($first['token'], $again['token']);
        $this->assertNull(AssetHelperToken::findValid($first['token']));
        $this->assertNotNull(AssetHelperToken::findValid($again['token']));
    }

    public function test_the_token_expiring_soon_is_rotated_by_the_next_heartbeat(): void
    {
        AssetHelperToken::where('asset_id', $this->asset->id)->update(['expires_at' => now()->addHours(2)]);

        $this->withToken($this->agentToken)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'X'])
            ->assertOk()->assertJsonStructure(['helper' => ['token', 'expires_at']]);
    }

    public function test_the_helper_token_cannot_be_used_for_agent_endpoints(): void
    {
        $this->withToken($this->helperToken)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'X'])->assertUnauthorized();
        $this->withToken($this->agentToken)->getJson('/api/helper/v1/poll')->assertUnauthorized();
    }

    public function test_requests_without_a_valid_token_are_rejected(): void
    {
        $this->getJson('/api/helper/v1/poll')->assertUnauthorized();
        $this->poll([], 'salah')->assertUnauthorized();
        $this->poll([], str_repeat('a', 40))->assertUnauthorized();

        AssetHelperToken::where('asset_id', $this->asset->id)->update(['expires_at' => now()->subMinute()]);
        $this->poll()->assertUnauthorized();
    }

    public function test_revoking_the_agent_token_cuts_off_the_helper(): void
    {
        $this->poll()->assertOk();

        $this->asset->agentTokens()->update(['revoked_at' => now()]);

        $this->poll()->assertUnauthorized();
    }

    // ---------- pengumuman ----------

    public function test_announcements_for_all_are_delivered_and_shown_only_once(): void
    {
        $a = $this->announcement(['title' => 'Halo semua']);

        $this->assertSame(['Halo semua'], array_column($this->poll()->assertOk()->json('announcements'), 'title'));

        $this->withToken($this->helperToken)->postJson("/api/helper/v1/announcements/{$a->id}/shown")->assertOk();

        $this->assertSame([], $this->poll()->json('announcements'));
    }

    public function test_selected_announcements_only_reach_their_targets(): void
    {
        $other = $this->makeAsset();
        $this->announcement(['title' => 'Khusus', 'target_mode' => 'selected'], [$other->id]);
        $this->announcement(['title' => 'Untuk kita', 'target_mode' => 'selected'], [$this->asset->id]);

        $this->assertSame(['Untuk kita'], array_column($this->poll()->json('announcements'), 'title'));
    }

    public function test_only_live_announcements_are_delivered(): void
    {
        $this->announcement(['title' => 'belum mulai', 'starts_at' => now()->addHour()]);
        $this->announcement(['title' => 'sudah berakhir', 'ends_at' => now()->subMinute()]);
        $this->announcement(['title' => 'dihentikan', 'ended_at' => now()->subMinute()]);
        $this->announcement(['title' => 'tayang', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);

        $this->assertSame(['tayang'], array_column($this->poll()->json('announcements'), 'title'));
    }

    public function test_required_announcements_keep_coming_until_acknowledged(): void
    {
        $a = $this->announcement(['title' => 'Wajib baca', 'require_ack' => true]);

        $this->withToken($this->helperToken)->postJson("/api/helper/v1/announcements/{$a->id}/shown")->assertOk();
        $this->assertCount(1, $this->poll()->json('announcements'));   // tampil tidak cukup

        $this->withToken($this->helperToken)->postJson("/api/helper/v1/announcements/{$a->id}/ack")->assertOk();
        $this->assertSame([], $this->poll()->json('announcements'));

        $delivery = $a->deliveries()->firstOrFail();
        $this->assertNotNull($delivery->shown_at);
        $this->assertNotNull($delivery->acked_at);
    }

    public function test_an_announcement_not_targeted_at_this_asset_cannot_be_acknowledged(): void
    {
        $other = $this->makeAsset();
        $a = $this->announcement(['target_mode' => 'selected'], [$other->id]);

        $this->withToken($this->helperToken)->postJson("/api/helper/v1/announcements/{$a->id}/ack")->assertNotFound();
        $this->assertSame(0, $a->deliveries()->count());
    }

    public function test_urgent_comes_first_and_at_most_five_per_poll(): void
    {
        $this->announcement(['title' => 'info 1']);
        $this->announcement(['title' => 'urgent', 'severity' => 'urgent']);
        $this->announcement(['title' => 'penting', 'severity' => 'important']);
        foreach (range(2, 7) as $i) {
            $this->announcement(['title' => "info $i"]);
        }

        $titles = array_column($this->poll()->json('announcements'), 'title');

        $this->assertCount(5, $titles);
        $this->assertSame(['urgent', 'penting'], array_slice($titles, 0, 2));
    }

    // ---------- chat ----------

    public function test_without_a_session_the_poll_has_no_chat_and_waits_20_seconds(): void
    {
        $res = $this->poll()->assertOk();

        $this->assertNull($res->json('chat.session'));
        $this->assertSame(20, $res->json('poll_seconds'));
    }

    public function test_the_user_can_reply_in_an_open_session(): void
    {
        $s = $this->openChat();

        $this->withToken($this->helperToken)->postJson('/api/helper/v1/chat/messages', ['session_id' => $s->id, 'body' => "Baik, terima kasih\x00"])
            ->assertOk()->assertJsonPath('status', 'ok');

        $m = AgentChatMessage::firstOrFail();
        $this->assertSame('user', $m->sender);
        $this->assertNull($m->user_id);
        $this->assertSame('Baik, terima kasih', $m->body);
        $this->assertNotNull($s->fresh()->last_activity_at);
    }

    public function test_replies_are_validated_and_rejected_on_closed_sessions(): void
    {
        $s = $this->openChat();

        $this->withToken($this->helperToken)->postJson('/api/helper/v1/chat/messages', ['session_id' => $s->id, 'body' => ''])
            ->assertOk()->assertJsonPath('status', 'error');
        $this->withToken($this->helperToken)->postJson('/api/helper/v1/chat/messages', ['session_id' => $s->id, 'body' => str_repeat('a', AgentChatSession::MESSAGE_MAX + 1)])
            ->assertOk()->assertJsonPath('status', 'error');

        $s->update(['status' => 'closed', 'closed_at' => now()]);
        $this->withToken($this->helperToken)->postJson('/api/helper/v1/chat/messages', ['session_id' => $s->id, 'body' => 'halo'])->assertStatus(409);
        $this->assertSame(0, AgentChatMessage::count());
    }

    public function test_a_user_cannot_post_into_another_assets_session(): void
    {
        $other = $this->makeAsset();
        $foreign = $this->openChat($other);

        $this->withToken($this->helperToken)->postJson('/api/helper/v1/chat/messages', ['session_id' => $foreign->id, 'body' => 'halo'])->assertStatus(409);
        $this->assertSame(0, AgentChatMessage::count());
    }

    public function test_the_user_has_no_way_to_start_or_close_a_session(): void
    {
        $s = $this->openChat();

        foreach (['chat/start', 'chat/close', "chat/{$s->id}/close", 'chat/sessions'] as $path) {
            $this->withToken($this->helperToken)->postJson("/api/helper/v1/$path", ['session_id' => $s->id])->assertStatus(404);
        }
        $this->assertTrue($s->fresh()->isOpen());
    }

    public function test_a_closed_session_is_reported_to_the_helper_that_still_holds_it(): void
    {
        $s = $this->openChat();
        $s->update(['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'admin']);

        $res = $this->poll(['chat_session' => $s->id])->assertOk();

        $this->assertSame('closed', $res->json('chat.session.status'));
        $this->assertSame('admin', $res->json('chat.session.close_reason'));
        $this->assertSame(20, $res->json('poll_seconds'));

        // helper sudah melepas sesi itu: tidak dilaporkan lagi
        $this->assertNull($this->poll()->json('chat.session'));
    }

    public function test_an_idle_session_is_closed_automatically(): void
    {
        $s = $this->openChat();
        $s->update(['last_activity_at' => now()->subMinutes(AgentChatSession::IDLE_MINUTES + 1)]);

        $res = $this->poll(['chat_session' => $s->id])->assertOk();

        $this->assertSame('closed', $res->json('chat.session.status'));
        $this->assertSame('idle', $res->json('chat.session.close_reason'));
    }
}
