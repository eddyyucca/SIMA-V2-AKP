<?php

namespace Tests\Agent;

use App\Filament\Resources\AgentAnnouncements\Pages\ManageAgentAnnouncements;
use App\Filament\Resources\AgentMonitor\Pages\ListAgentMonitor;
use App\Models\AgentAnnouncement;
use App\Models\AssetAgentCommand;
use App\Models\AssetAgentToken;
use App\Models\User;
use App\Services\AgentAdminService;
use Livewire\Livewire;
use Tests\AgentTestCase;

/** Sisi admin agent (pengganti tes UI Snipe-IT lama): token, perintah, chat, pengumuman, halaman Filament. */
class AgentAdminTest extends AgentTestCase
{
    private function admin(): User { return User::where('email', 'admin@inventaris.test')->firstOrFail(); }
    private function staff(): User { return User::where('email', 'staff@inventaris.test')->firstOrFail(); }
    private function svc(): AgentAdminService { return app(AgentAdminService::class); }

    public function test_issue_token_revokes_the_previous_one_and_revoke_cuts_off_the_agent(): void
    {
        $asset = $this->makeAsset();
        $first = $this->svc()->issueToken($asset, $this->staff());
        $this->withToken($first)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'A'])->assertOk()->assertJsonPath('status', 'ok');

        $second = $this->svc()->issueToken($asset, $this->staff());
        $this->withToken($first)->postJson('/api/agent/v1/heartbeat', [])->assertUnauthorized();
        $this->withToken($second)->postJson('/api/agent/v1/heartbeat', [])->assertOk();

        $this->svc()->revokeToken($asset);
        $this->withToken($second)->postJson('/api/agent/v1/heartbeat', [])->assertUnauthorized();
    }

    public function test_commands_are_validated_deduplicated_capped_and_restart_is_admin_only(): void
    {
        $asset = $this->makeAsset();
        $staff = $this->staff(); $admin = $this->admin();

        $this->assertTrue($this->svc()->queueCommand($asset, 'notify_user', ['message' => "Halo\n\n  tes"], $staff)['ok']);
        $this->assertSame('Halo tes', $asset->agentCommands()->first()->params['message']);
        $this->assertFalse($this->svc()->queueCommand($asset, 'notify_user', ['message' => ''], $staff)['ok']);

        $types = collect(AssetAgentCommand::TYPES)->reject(fn ($t) => in_array($t, AssetAgentCommand::ADMIN_ONLY_TYPES, true) || $t === 'notify_user')->values();
        $this->assertTrue($this->svc()->queueCommand($asset, $types[0], [], $staff)['ok']);
        $this->assertSame('warning', $this->svc()->queueCommand($asset, $types[0], [], $staff)['level']);   // tidak ditumpuk

        $this->assertFalse($this->svc()->queueCommand($asset, 'restart_computer', ['delay_minutes' => 10, 'confirm' => true], $staff)['ok']);   // bukan Admin
        $this->assertFalse($this->svc()->queueCommand($asset, 'restart_computer', ['delay_minutes' => 1, 'confirm' => true], $admin)['ok']);       // jeda di luar rentang
        $this->assertFalse($this->svc()->queueCommand($asset, 'restart_computer', ['delay_minutes' => 10], $admin)['ok']);                         // tanpa konfirmasi
        $this->assertTrue($this->svc()->queueCommand($asset, 'restart_computer', ['delay_minutes' => 10, 'confirm' => true], $admin)['ok']);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->queueCommand($asset, 'format_disk', [], $admin);
    }

    public function test_waiting_command_is_delivered_to_the_agent_and_can_be_cancelled_only_while_active(): void
    {
        $asset = $this->makeAsset();
        $token = $this->svc()->issueToken($asset, $this->staff());
        $this->svc()->queueCommand($asset, 'notify_user', ['message' => 'Tes'], $this->staff());
        $cmd = $asset->agentCommands()->first();

        $r = $this->withToken($token)->postJson('/api/agent/v1/heartbeat', [])->assertOk();
        $this->assertSame($cmd->id, $r->json('commands.0.id'));
        $this->assertSame('sent', $cmd->fresh()->status);

        $other = $this->makeAsset();
        $this->assertFalse($this->svc()->cancelCommand($other, $cmd));            // aset lain
        $this->assertTrue($this->svc()->cancelCommand($asset, $cmd));
        $this->assertFalse($this->svc()->cancelCommand($asset, $cmd->fresh()));   // sudah dibatalkan
    }

    public function test_chat_flow_start_reply_close(): void
    {
        $asset = $this->makeAsset();
        $this->assertFalse($this->svc()->startChat($asset, $this->staff())['ok']);          // belum ada agent
        $token = $this->svc()->issueToken($asset, $this->staff());
        $this->assertTrue($this->svc()->startChat($asset, $this->staff())['ok']);
        $this->assertFalse($this->svc()->startChat($asset, $this->staff())['ok']);          // sudah terbuka

        $session = $asset->chatSessions()->first();
        $this->assertFalse($this->svc()->sendChat($session, '   ', $this->staff())['ok']);
        $this->assertTrue($this->svc()->sendChat($session, "Halo\x07 pak", $this->staff())['ok']);
        $this->assertSame('Halo pak', $session->messages()->first()->body);

        $this->svc()->closeChat($session, $this->staff());
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertFalse($this->svc()->sendChat($session->fresh(), 'lagi', $this->staff())['ok']);
    }

    public function test_announcement_create_validate_stop_delete(): void
    {
        $a1 = $this->makeAsset(); $this->svc()->issueToken($a1, $this->admin());
        $this->makeAsset();   // tanpa agent: tidak boleh jadi sasaran

        $ann = $this->svc()->createAnnouncement(['title' => 'Maintenance', 'body' => 'Malam ini', 'severity' => 'urgent', 'require_ack' => true, 'target_mode' => 'all'], $this->admin());
        $this->assertSame('live', $ann->state());

        $sel = $this->svc()->createAnnouncement(['title' => 'X', 'body' => 'Y', 'severity' => 'info', 'target_mode' => 'selected', 'asset_ids' => [$a1->id, 999999]], $this->admin());
        $this->assertSame([$a1->id], $sel->targets()->pluck('asset_id')->all());

        $this->assertThrows(fn () => $this->svc()->createAnnouncement(['title' => 'X', 'body' => 'Y', 'severity' => 'info', 'target_mode' => 'selected', 'asset_ids' => [999999]], $this->admin()), \InvalidArgumentException::class);
        $this->assertThrows(fn () => $this->svc()->createAnnouncement(['title' => 'X', 'body' => 'Y', 'severity' => 'ngawur', 'target_mode' => 'all'], $this->admin()), \InvalidArgumentException::class);

        $this->svc()->stopAnnouncement($ann);
        $this->assertSame('stopped', $ann->fresh()->state());
        $this->svc()->deleteAnnouncement($sel);
        $this->assertNull(AgentAnnouncement::find($sel->id));
    }

    public function test_purge_removes_agent_data_but_keeps_the_asset(): void
    {
        $asset = $this->makeAsset();
        $token = $this->svc()->issueToken($asset, $this->staff());
        $this->withToken($token)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'X'])->assertOk();
        $this->assertSame('online', $asset->fresh()->agent_status);

        $this->svc()->purge($asset);
        $asset = $asset->fresh();
        $this->assertNull($asset->agentHeartbeat);
        $this->assertSame('never_reported', $asset->agent_status);
        $this->assertSame(0, AssetAgentToken::where('asset_id', $asset->id)->whereNull('revoked_at')->count());
        $this->withToken($token)->postJson('/api/agent/v1/heartbeat', [])->assertUnauthorized();
    }

    public function test_asset_detail_shows_agent_card_and_monitor_lists_the_device(): void
    {
        $asset = $this->makeAsset();
        $token = $this->svc()->issueToken($asset, $this->admin());
        $this->withToken($token)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'LAPTOP-<b>XSS</b>', 'ip_address' => '10.1.1.9', 'os_version' => 'Windows 11'])->assertOk();
        auth()->forgetGuards();

        $html = $this->actingAs($this->admin())->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $asset]))->assertOk()->getContent();
        $this->assertStringContainsString('Perangkat (Agent)', $html);
        $this->assertStringContainsString('10.1.1.9', $html);
        $this->assertStringNotContainsString('<b>XSS</b>', $html);   // di-escape

        Livewire::actingAs($this->staff())->test(ListAgentMonitor::class)->set('tableRecordsPerPage', 100)->assertCanSeeTableRecords([$asset]);
    }

    public function test_announcements_page_is_admin_only(): void
    {
        $this->actingAs($this->staff())->get(\App\Filament\Resources\AgentAnnouncements\AgentAnnouncementResource::getUrl())->assertForbidden();
        auth()->forgetGuards(); $this->flushSession();
        $this->actingAs($this->admin())->get(\App\Filament\Resources\AgentAnnouncements\AgentAnnouncementResource::getUrl())->assertOk();
        Livewire::actingAs($this->admin())->test(ManageAgentAnnouncements::class)->assertOk();
    }
}
