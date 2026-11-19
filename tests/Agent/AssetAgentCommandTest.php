<?php

namespace Tests\Agent;

use App\Models\Asset;
use App\Models\AssetAgentCommand;
use App\Models\AssetAgentToken;
use App\Models\User;
use Tests\AgentTestCase;

/** Perintah dari daftar tetap untuk agent di laptop terpilih. */
class AssetAgentCommandTest extends AgentTestCase
{
    private Asset $asset;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();


        $this->asset = $this->makeAsset();
        [, $this->token] = AssetAgentToken::issueFor($this->asset, null);
    }

    private function make(string $type = 'refresh', array $attrs = [], ?Asset $asset = null): AssetAgentCommand
    {
        return ($asset ?? $this->asset)->agentCommands()->create($attrs + [
            'type' => $type, 'status' => 'pending', 'requested_at' => now(), 'expires_at' => now()->addHours(24),
        ]);
    }

    private function heartbeat(?string $token = null)
    {
        return $this->withToken($token ?? $this->token)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'X']);
    }

    // ---------- meminta perintah (web) ----------

    public function test_heartbeat_delivers_waiting_commands_and_marks_them_sent(): void
    {
        $refresh = $this->make('refresh');
        $msg = $this->make('notify_user', ['params' => ['message' => 'Halo']]);

        $res = $this->heartbeat()->assertOk()->json();

        $this->assertSame([$refresh->id, $msg->id], array_column($res['commands'], 'id'));
        $this->assertSame('Halo', $res['commands'][1]['params']['message']);
        $this->assertSame('sent', $refresh->fresh()->status);
        $this->assertNotNull($refresh->fresh()->delivered_at);

        // Perintah yang sudah terkirim tapi belum ada hasilnya dikirim lagi (agent memakai id untuk mencegah eksekusi ganda).
        $this->assertCount(2, $this->heartbeat()->json('commands'));
    }

    public function test_a_report_without_commands_returns_an_empty_list(): void
    {
        $this->assertSame([], $this->heartbeat()->assertOk()->json('commands'));
    }

    public function test_commands_of_other_assets_are_never_delivered(): void
    {
        $other = $this->makeAsset();
        $this->make('refresh', [], $other);

        $this->assertSame([], $this->heartbeat()->json('commands'));
    }

    public function test_expired_cancelled_and_finished_commands_are_not_delivered(): void
    {
        $expired = $this->make('refresh', ['expires_at' => now()->subMinute()]);
        $this->make('diagnostics', ['status' => 'cancelled']);
        $this->make('collect_log', ['status' => 'done']);

        $this->assertSame([], $this->heartbeat()->json('commands'));
        $this->assertSame('expired', $expired->fresh()->status);
    }

    // ---------- agent melaporkan hasil (API) ----------

    public function test_the_agent_can_report_a_result(): void
    {
        $cmd = $this->make('diagnostics', ['status' => 'sent']);

        $this->withToken($this->token)->postJson("/api/agent/v1/commands/{$cmd->id}/result", ['status' => 'done', 'result' => "baris 1\nbaris 2"])
            ->assertOk()->assertJson(['status' => 'ok']);

        $cmd->refresh();
        $this->assertSame('done', $cmd->status);
        $this->assertSame("baris 1\nbaris 2", $cmd->result);
        $this->assertNotNull($cmd->finished_at);
    }

    public function test_a_result_cannot_be_posted_for_another_assets_command(): void
    {
        $other = $this->makeAsset();
        $foreign = $this->make('refresh', ['status' => 'sent'], $other);

        $this->withToken($this->token)->postJson("/api/agent/v1/commands/{$foreign->id}/result", ['status' => 'done'])->assertNotFound();
        $this->assertSame('sent', $foreign->fresh()->status);
    }

    public function test_a_result_for_a_finished_or_cancelled_command_is_ignored(): void
    {
        $cancelled = $this->make('refresh', ['status' => 'cancelled']);

        $this->withToken($this->token)->postJson("/api/agent/v1/commands/{$cancelled->id}/result", ['status' => 'done', 'result' => 'x'])
            ->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    public function test_result_status_is_validated_and_result_text_is_truncated(): void
    {
        $cmd = $this->make('collect_log', ['status' => 'sent']);

        $this->withToken($this->token)->postJson("/api/agent/v1/commands/{$cmd->id}/result", ['status' => 'hacked'])
            ->assertOk()->assertJsonPath('status', 'error');

        $this->withToken($this->token)->postJson("/api/agent/v1/commands/{$cmd->id}/result", [
            'status' => 'done', 'result' => str_repeat('x', AssetAgentCommand::RESULT_MAX + 5000),
        ])->assertOk();
        $this->assertSame(AssetAgentCommand::RESULT_MAX, mb_strlen($cmd->fresh()->result));
    }

    public function test_the_result_endpoint_requires_an_agent_token(): void
    {
        $cmd = $this->make('refresh', ['status' => 'sent']);

        $this->postJson("/api/agent/v1/commands/{$cmd->id}/result", ['status' => 'done'])->assertUnauthorized();
    }

    // ---------- halaman ----------
}
