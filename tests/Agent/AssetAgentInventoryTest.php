<?php

namespace Tests\Agent;

use App\Models\Asset;
use App\Models\AssetAgentToken;
use App\Models\User;
use Tests\AgentTestCase;

/** Agent v4: jaringan, baterai, semua disk, Windows Update tertunda, dan software terpasang. */
class AssetAgentInventoryTest extends AgentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

    }

    private function token(Asset $asset): string
    {
        [, $plaintext] = AssetAgentToken::issueFor($asset, null);

        return $plaintext;
    }

    private function fullReport(): array
    {
        return [
            'hostname' => 'LAPTOP-V4',
            'agent_version' => 4,
            'network_type' => 'wifi',
            'wifi_ssid' => 'AKP_MGT',
            'mac_address' => 'EC-91-61-8C-5C-13',
            'gateway' => '172.20.0.1',
            'battery_present' => true,
            'battery_percent' => 87,
            'battery_charging' => true,
            'battery_health_percent' => 72,
            'disks' => [
                ['drive' => 'C:', 'total' => 466037837824, 'free' => 68611231744],
                ['drive' => 'D:', 'total' => 525564112896, 'free' => 462297616384],
            ],
            'physical_disks' => [
                ['name' => 'MTFDKBA1T0QFM', 'media' => 'SSD', 'size' => 1024209543168, 'health' => 'healthy'],
            ],
            'reboot_pending' => true,
        ];
    }

    private function inventory(): array
    {
        return [
            'software' => [
                ['name' => 'Google Chrome', 'version' => '130.0', 'publisher' => 'Google LLC'],
                ['name' => 'Notepad++ <script>', 'version' => '8.6', 'publisher' => null],
            ],
            'pending_updates_count' => 2,
            'pending_updates' => ['2026-10 Cumulative Update (KB5000001)', 'Security Intelligence Update'],
            'reboot_pending' => false,
        ];
    }

    public function test_stores_network_battery_and_disk_details(): void
    {
        $asset = $this->makeAsset();

        $this->withToken($this->token($asset))->postJson('/api/agent/v1/heartbeat', $this->fullReport())->assertOk();

        $hb = $asset->agentHeartbeat()->first();
        $this->assertSame('wifi', $hb->network_type);
        $this->assertSame('AKP_MGT', $hb->wifi_ssid);
        $this->assertSame('172.20.0.1', $hb->gateway);
        $this->assertTrue($hb->battery_present);
        $this->assertSame(87, $hb->battery_percent);
        $this->assertSame(72, $hb->battery_health_percent);
        $this->assertSame('D:', $hb->disks[1]['drive']);
        $this->assertSame('healthy', $hb->physical_disks[0]['health']);
        $this->assertTrue($hb->reboot_pending);
    }

    public function test_inventory_is_stored_with_counts_and_timestamps(): void
    {
        $asset = $this->makeAsset();

        $this->withToken($this->token($asset))->postJson('/api/agent/v1/heartbeat', $this->inventory())->assertOk();

        $hb = $asset->agentHeartbeat()->first();
        $this->assertSame(2, $hb->software_count);
        $this->assertNotNull($hb->software_reported_at);
        $this->assertSame('Google Chrome', $hb->software[0]['name']);
        $this->assertSame(2, $hb->pending_updates_count);
        $this->assertNotNull($hb->updates_checked_at);
        $this->assertCount(2, $hb->pending_updates);
    }

    public function test_regular_report_does_not_wipe_the_daily_inventory(): void
    {
        $asset = $this->makeAsset();
        $token = $this->token($asset);

        $this->withToken($token)->postJson('/api/agent/v1/heartbeat', $this->inventory())->assertOk();
        $this->withToken($token)->postJson('/api/agent/v1/heartbeat', $this->fullReport())->assertOk();

        $hb = $asset->agentHeartbeat()->first();
        $this->assertSame('LAPTOP-V4', $hb->hostname);
        $this->assertSame(2, $hb->software_count);
        $this->assertSame(2, $hb->pending_updates_count);
        $this->assertSame(1, \App\Models\AssetHeartbeat::where('asset_id', $asset->id)->count());
    }

    public function test_rejects_invalid_values(): void
    {
        $token = $this->token($this->makeAsset());

        // Konvensi API Snipe-IT: error validasi dibalas HTTP 200 dengan status "error" di body (bukan 422).
        $rejected = fn (array $payload, string $field) => $this->withToken($token)
            ->postJson('/api/agent/v1/heartbeat', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['messages' => [$field]]);

        $rejected(['network_type' => 'bluetooth'], 'network_type');
        $rejected(['battery_percent' => 101], 'battery_percent');
        $rejected(['gateway' => 'bukan-ip'], 'gateway');
        $rejected(['physical_disks' => [['name' => 'x', 'health' => 'rusak']]], 'physical_disks.0.health');
        $rejected(['software' => [['version' => '1']]], 'software.0.name');
        $rejected(['software' => array_fill(0, 1501, ['name' => 'x'])], 'software');
    }

    private function cpuRamReport(): array
    {
        return [
            'cpu' => 'AMD Ryzen AI 9 HX 370',
            'cpu_manufacturer' => 'AuthenticAMD',
            'cpu_cores' => 12,
            'cpu_logical' => 24,
            'cpu_max_mhz' => 2000,
            'cpu_current_mhz' => 2000,
            'cpu_load_percent' => 4,
            'cpu_l2_kb' => 12288,
            'cpu_l3_kb' => 24576,
            'ram_total_bytes' => 33362391040,
            'ram_free_bytes' => 5240586240,
            'ram_slots_total' => 4,
            'ram_max_bytes' => 68719476736,
            'ram_modules' => [
                ['slot' => 'DIMM 0', 'capacity' => 8589934592, 'speed_mhz' => 7500, 'type' => 'LPDDR5',
                    'form_factor' => null, 'manufacturer' => 'Samsung', 'part_number' => 'K3KL9L90CM-MGCT'],
                ['slot' => 'DIMM 1', 'capacity' => 8589934592, 'speed_mhz' => 7500, 'type' => 'LPDDR5',
                    'form_factor' => 'SODIMM', 'manufacturer' => 'Samsung <b>x</b>', 'part_number' => null],
            ],
        ];
    }

    public function test_rejects_invalid_cpu_and_ram_values(): void
    {
        $token = $this->token($this->makeAsset());

        foreach ([
            ['cpu_load_percent' => 150],
            ['cpu_cores' => 0],
            ['ram_slots_total' => 500],
            ['ram_free_bytes' => -1],
            ['ram_modules' => [['capacity' => -5]]],
        ] as $bad) {
            $this->withToken($token)->postJson('/api/agent/v1/heartbeat', $bad)->assertOk()->assertJsonPath('status', 'error');
        }
    }
}
