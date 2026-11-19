<?php

namespace Tests\Agent;

use App\Models\Asset;
use App\Models\AssetAgentToken;
use Tests\AgentTestCase;

class AssetAgentHeartbeatTest extends AgentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

    }

    public function test_many_laptops_behind_one_ip_do_not_throttle_each_other(): void
    {
        // Seluruh permintaan uji berasal dari satu IP, seperti laptop kantor di belakang satu IP publik.
        for ($i = 0; $i < 30; $i++) {
            $asset = $this->makeAsset();
            [, $token] = AssetAgentToken::issueFor($asset, null);

            $this->withToken($token)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'PC-'.$i])->assertOk();
        }
    }

    public function test_one_noisy_device_is_still_limited_by_its_own_token(): void
    {
        $asset = $this->makeAsset();
        [, $token] = AssetAgentToken::issueFor($asset, null);

        for ($i = 0; $i < 20; $i++) {
            $this->withToken($token)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'PC'])->assertOk();
        }
        $this->withToken($token)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'PC'])->assertStatus(429);

        // Laptop lain dari IP yang sama tidak terdampak.
        [, $other] = AssetAgentToken::issueFor($this->makeAsset(), null);
        $this->withToken($other)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'PC2'])->assertOk();
    }

    public function test_rejects_requests_without_a_token(): void
    {
        $this->postJson('/api/agent/v1/heartbeat', ['hostname' => 'X'])->assertUnauthorized();
        $this->withToken('salah')->postJson('/api/agent/v1/heartbeat', ['hostname' => 'X'])->assertUnauthorized();
    }

    public function test_valid_token_updates_asset_status_and_stores_a_heartbeat(): void
    {
        $asset = $this->makeAsset();
        [, $plaintext] = AssetAgentToken::issueFor($asset, null);

        $response = $this->withToken($plaintext)->postJson('/api/agent/v1/heartbeat', [
            'hostname' => 'LAPTOP-TEST',
            'ip_address' => '10.0.0.5',
            'os_version' => 'Windows 11 Pro',
            'disk_free_bytes' => 123456,
            'agent_version' => 1,
        ])->assertOk()->assertJson(['status' => 'ok']);

        $asset->refresh();
        $this->assertSame('online', $asset->agent_status);
        $this->assertNotNull($asset->agent_last_seen);
        $this->assertSame('LAPTOP-TEST', $asset->agentHeartbeat->hostname);
    }

    public function test_repeated_heartbeats_update_the_same_row_instead_of_accumulating(): void
    {
        // Dengan ratusan aset melapor tiap beberapa menit, asset_heartbeats HARUS tetap satu baris
        // per aset (upsert), bukan log yang terus bertambah.
        $asset = $this->makeAsset();
        [, $plaintext] = AssetAgentToken::issueFor($asset, null);

        $this->withToken($plaintext)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'LAPTOP-LAMA'])->assertOk();
        $this->withToken($plaintext)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'LAPTOP-BARU'])->assertOk();
        $this->withToken($plaintext)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'LAPTOP-TERBARU'])->assertOk();

        $this->assertSame(1, \App\Models\AssetHeartbeat::where('asset_id', $asset->id)->count());
        $this->assertSame('LAPTOP-TERBARU', $asset->agentHeartbeat()->first()->hostname);
    }

    public function test_revoked_token_is_rejected(): void
    {
        $asset = $this->makeAsset();
        [$token, $plaintext] = AssetAgentToken::issueFor($asset, null);
        $token->update(['revoked_at' => now()]);

        $this->withToken($plaintext)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'X'])->assertUnauthorized();
    }

    public function test_token_only_reports_for_its_own_asset(): void
    {
        $assetA = $this->makeAsset();
        $assetB = $this->makeAsset();
        [, $plaintext] = AssetAgentToken::issueFor($assetA, null);

        $this->withToken($plaintext)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'X'])->assertOk();

        $this->assertSame('online', $assetA->fresh()->agent_status);
        $this->assertSame('never_reported', $assetB->fresh()->agent_status);
    }

    public function test_version_endpoint_is_public(): void
    {
        $this->getJson('/api/agent/v1/version')->assertOk()->assertJsonStructure(['agent_version']);
    }

    public function test_stores_hardware_and_compliance_detail_fields(): void
    {
        $asset = $this->makeAsset();
        [, $plaintext] = AssetAgentToken::issueFor($asset, null);

        $this->withToken($plaintext)->postJson('/api/agent/v1/heartbeat', [
            'hostname' => 'LAPTOP-DETAIL', 'manufacturer' => 'Dell Inc.', 'model' => 'Latitude 5440',
            'serial_number' => 'SN123', 'cpu' => 'Intel Core i7', 'ram_total_bytes' => 17179869184,
            'disk_free_bytes' => 92682567680, 'disk_total_bytes' => 512110190592,
            'os_build' => '22631', 'os_architecture' => '64-bit', 'domain' => 'AKP.LOCAL',
            'last_boot_at' => now()->subHours(5)->toIso8601String(),
            'bitlocker_status' => 'on', 'defender_enabled' => true,
            'defender_signature_at' => now()->subDay()->toIso8601String(),
            'last_patch_at' => now()->subDays(10)->toIso8601String(),
        ])->assertOk();

        $heartbeat = $asset->agentHeartbeat()->first();
        $this->assertSame('Dell Inc.', $heartbeat->manufacturer);
        $this->assertSame('Latitude 5440', $heartbeat->model);
        $this->assertSame('SN123', $heartbeat->serial_number);
        $this->assertSame('Intel Core i7', $heartbeat->cpu);
        $this->assertSame(17179869184, $heartbeat->ram_total_bytes);
        $this->assertSame(512110190592, $heartbeat->disk_total_bytes);
        $this->assertSame('22631', $heartbeat->os_build);
        $this->assertSame('64-bit', $heartbeat->os_architecture);
        $this->assertSame('AKP.LOCAL', $heartbeat->domain);
        $this->assertNotNull($heartbeat->last_boot_at);
        $this->assertSame('on', $heartbeat->bitlocker_status);
        $this->assertTrue($heartbeat->defender_enabled);
        $this->assertNotNull($heartbeat->defender_signature_at);
        $this->assertNotNull($heartbeat->last_patch_at);
    }

    public function test_detail_fields_are_optional(): void
    {
        $asset = $this->makeAsset();
        [, $plaintext] = AssetAgentToken::issueFor($asset, null);

        // Edisi Windows Home (tanpa BitLocker) atau Defender diganti antivirus lain -> field-field ini kosong.
        $this->withToken($plaintext)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'LAPTOP-MINIMAL'])
            ->assertOk();

        $heartbeat = $asset->agentHeartbeat()->first();
        $this->assertSame('LAPTOP-MINIMAL', $heartbeat->hostname);
        $this->assertNull($heartbeat->manufacturer);
        $this->assertNull($heartbeat->bitlocker_status);
        $this->assertNull($heartbeat->defender_enabled);
    }

    public function test_rejects_an_invalid_bitlocker_status(): void
    {
        $asset = $this->makeAsset();
        [, $plaintext] = AssetAgentToken::issueFor($asset, null);

        // Konvensi API Snipe-IT di seluruh aplikasi: error validasi dibalas HTTP 200 dengan status "error"
        // di body (lihat Handler::invalidJson()), bukan 422. Bukan khusus endpoint ini.
        $this->withToken($plaintext)->postJson('/api/agent/v1/heartbeat', ['bitlocker_status' => 'ya-lah'])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['messages' => ['bitlocker_status']]);

        $this->assertNull($asset->fresh()->agentHeartbeat);
    }

    public function test_serial_mismatch_is_detected(): void
    {
        $asset = $this->makeAsset(['serial_number' => 'ASLI-123']);
        [, $plaintext] = AssetAgentToken::issueFor($asset, null);

        $this->withToken($plaintext)->postJson('/api/agent/v1/heartbeat', ['serial_number' => 'BEDA-456'])
            ->assertOk();

        $this->assertTrue($asset->agentHeartbeat()->first()->serialMismatchesAsset());
    }

    public function test_matching_serial_is_not_flagged(): void
    {
        $asset = $this->makeAsset(['serial_number' => 'SAMA-123']);
        [, $plaintext] = AssetAgentToken::issueFor($asset, null);

        // Bandingkan tanpa peduli besar/kecil huruf dan spasi pinggir.
        $this->withToken($plaintext)->postJson('/api/agent/v1/heartbeat', ['serial_number' => ' sama-123 '])
            ->assertOk();

        $this->assertFalse($asset->agentHeartbeat()->first()->serialMismatchesAsset());
    }
}
