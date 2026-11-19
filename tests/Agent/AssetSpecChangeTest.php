<?php

namespace Tests\Agent;

use App\Models\Asset;
use App\Models\AssetAgentToken;
use App\Models\AssetSpecChange;
use App\Models\User;
use Tests\AgentTestCase;

/** Riwayat perubahan spesifikasi: dicatat server saat laporan agent berbeda dari laporan sebelumnya. */
class AssetSpecChangeTest extends AgentTestCase
{
    private Asset $asset;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();


        $this->asset = $this->makeAsset();
        [, $this->token] = AssetAgentToken::issueFor($this->asset, null);
    }

    private function report(array $overrides = []): void
    {
        $base = [
            'hostname' => 'LAPTOP-A',
            'manufacturer' => 'ASUS',
            'model' => 'ROG Zephyrus',
            'serial_number' => 'SN123',
            'cpu' => 'AMD Ryzen 9',
            'cpu_cores' => 12,
            'cpu_logical' => 24,
            'ram_total_bytes' => 34359738368,
            'disk_total_bytes' => 512110190592,
            'os_version' => 'Windows 11 Pro',
            'os_build' => '26100',
            // nilai dinamis: tidak boleh pernah tercatat sebagai perubahan
            'disk_free_bytes' => 100000000000,
            'cpu_load_percent' => 5,
            'ram_free_bytes' => 9000000000,
            'ip_address' => '10.0.0.5',
            'ram_modules' => [
                ['slot' => 'DIMM 0', 'capacity' => 17179869184, 'speed_mhz' => 5600, 'type' => 'DDR5', 'manufacturer' => 'Samsung'],
                ['slot' => 'DIMM 1', 'capacity' => 17179869184, 'speed_mhz' => 5600, 'type' => 'DDR5', 'manufacturer' => 'Samsung'],
            ],
            'physical_disks' => [['name' => 'WD SN850', 'media' => 'SSD', 'size' => 512110190592, 'health' => 'healthy']],
        ];

        $this->withToken($this->token)->postJson('/api/agent/v1/heartbeat', array_replace($base, $overrides))
            ->assertOk()->assertJson(['status' => 'ok']);
    }

    private function changes()
    {
        return AssetSpecChange::where('asset_id', $this->asset->id)->get()->keyBy('field');
    }

    public function test_first_report_is_a_baseline_and_logs_nothing(): void
    {
        $this->report();

        $this->assertSame(0, AssetSpecChange::count());
    }

    public function test_dynamic_values_never_create_log_entries(): void
    {
        $this->report();
        $this->report(['disk_free_bytes' => 50000000000, 'cpu_load_percent' => 90, 'ram_free_bytes' => 1000, 'ip_address' => '10.9.9.9']);

        $this->assertSame(0, AssetSpecChange::count());
    }

    public function test_hardware_and_os_changes_are_logged_with_old_and_new_values(): void
    {
        $this->report();
        $this->report([
            'cpu' => 'AMD Ryzen 9 PRO',
            'ram_total_bytes' => 68719476736,
            'os_version' => 'Windows 11 Enterprise',
            'ram_modules' => [
                ['slot' => 'DIMM 0', 'capacity' => 34359738368, 'speed_mhz' => 5600, 'type' => 'DDR5', 'manufacturer' => 'Samsung'],
                ['slot' => 'DIMM 1', 'capacity' => 34359738368, 'speed_mhz' => 5600, 'type' => 'DDR5', 'manufacturer' => 'Samsung'],
            ],
        ]);

        $c = $this->changes();
        $this->assertSame('hardware', $c['cpu']->category);
        $this->assertSame('AMD Ryzen 9', $c['cpu']->old_value);
        $this->assertSame('AMD Ryzen 9 PRO', $c['cpu']->new_value);
        $this->assertSame('32.0 GB', $c['ram_total_bytes']->old_value);
        $this->assertSame('64.0 GB', $c['ram_total_bytes']->new_value);
        $this->assertSame('os', $c['os_version']->category);
        $this->assertStringContainsString('16 GB DDR5', $c['ram_modules']->old_value);
        $this->assertStringContainsString('32 GB DDR5', $c['ram_modules']->new_value);
        $this->assertNotNull($c['cpu']->changed_at);
    }

    public function test_a_field_that_was_empty_before_is_not_a_change(): void
    {
        // Agent lama tidak mengirim detail CPU; agent baru mulai mengirimnya: itu bukan perubahan spesifikasi.
        $this->withToken($this->token)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'LAPTOP-A'])->assertOk();
        $this->report(['hostname' => 'LAPTOP-A']);

        $this->assertSame(0, AssetSpecChange::count());
    }

    public function test_an_empty_module_list_is_a_read_failure_not_removal(): void
    {
        $this->report();
        $this->report(['ram_modules' => [], 'physical_disks' => []]);

        $this->assertSame(0, AssetSpecChange::count());
    }

    public function test_software_added_removed_and_updated_are_logged(): void
    {
        $this->report(['software' => [
            ['name' => 'Google Chrome', 'version' => '130.0'],
            ['name' => 'Zoom', 'version' => '6.0'],
            ['name' => '7-Zip', 'version' => '23.01'],
        ]]);
        $this->report(['software' => [
            ['name' => 'Google Chrome', 'version' => '131.0'],     // diperbarui
            ['name' => '7-Zip', 'version' => '23.01'],              // tetap
            ['name' => 'AnyDesk', 'version' => '8.0'],              // ditambah (Zoom dicopot)
        ]]);

        $rows = AssetSpecChange::where('asset_id', $this->asset->id)->where('category', 'software')->get();

        $updated = $rows->firstWhere('field', 'software_updated');
        $this->assertSame('Google Chrome 130.0', $updated->old_value);
        $this->assertSame('Google Chrome 131.0', $updated->new_value);
        $this->assertSame('AnyDesk 8.0', $rows->firstWhere('field', 'software_added')->new_value);
        $this->assertSame('Zoom 6.0', $rows->firstWhere('field', 'software_removed')->old_value);
        $this->assertCount(3, $rows);
    }

    public function test_the_log_is_capped_per_asset(): void
    {
        $this->report();
        AssetSpecChange::insert(array_map(fn ($i) => [
            'asset_id' => $this->asset->id, 'category' => 'os', 'field' => 'os_build',
            'old_value' => (string) $i, 'new_value' => (string) ($i + 1), 'changed_at' => now()->subDays(2),
        ], range(1, 505)));

        $this->report(['cpu' => 'CPU Baru']);

        $this->assertLessThanOrEqual(500, AssetSpecChange::where('asset_id', $this->asset->id)->count());
        $this->assertSame('CPU Baru', AssetSpecChange::where('asset_id', $this->asset->id)->where('field', 'cpu')->value('new_value'));
    }

    public function test_benchmark_values_are_stored_and_validated(): void
    {
        $this->withToken($this->token)->postJson('/api/agent/v1/heartbeat', [
            'ram_read_mbps' => 48000, 'ram_write_mbps' => 41000, 'disk_read_mbps' => 3200, 'disk_write_mbps' => 2800,
        ])->assertOk()->assertJson(['status' => 'ok']);

        $hb = $this->asset->agentHeartbeat()->first();
        $this->assertSame(48000, $hb->ram_read_mbps);
        $this->assertSame(2800, $hb->disk_write_mbps);
        $this->assertNotNull($hb->benchmark_at);

        $this->withToken($this->token)->postJson('/api/agent/v1/heartbeat', ['disk_read_mbps' => -1])
            ->assertOk()->assertJsonPath('status', 'error');
    }
}
