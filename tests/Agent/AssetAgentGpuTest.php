<?php

namespace Tests\Agent;

use App\Filament\Resources\AgentMonitor\AgentMonitorResource;
use App\Models\Asset;
use App\Models\AssetAgentToken;
use App\Models\AssetSpecChange;
use App\Models\User;
use App\Services\AgentFleetMonitor;
use Filament\Facades\Filament;
use Tests\AgentTestCase;

/** Agent v10: data kartu grafis (VGA) dikirim, divalidasi, disimpan, tercatat perubahannya, dan tampil di monitor. */
class AssetAgentGpuTest extends AgentTestCase
{
    private function gpu(array $over = []): array
    {
        return $over + ['name' => 'NVIDIA GeForce RTX 4050 Laptop GPU', 'kind' => 'discrete', 'vram_bytes' => 6442450944, 'driver_version' => '31.0.15.5222',
            'driver_date' => '2024-05-02', 'processor' => 'NVIDIA GeForce RTX 4050', 'resolution' => '1920x1080', 'refresh_hz' => 144, 'status' => 'OK'];
    }

    private function intel(): array
    {
        return ['name' => 'Intel(R) UHD Graphics', 'kind' => 'integrated', 'vram_bytes' => 134217728, 'driver_version' => '31.0.101.4502', 'driver_date' => '2023-11-01'];
    }

    private function report(string $token, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($token)->postJson('/api/agent/v1/heartbeat', $body + ['hostname' => 'PC-GPU', 'agent_version' => 10]);
    }

    public function test_gpus_are_validated_stored_and_old_agents_do_not_overwrite_them(): void
    {
        $asset = $this->makeAsset();
        [, $token] = AssetAgentToken::issueFor($asset, null);

        $this->report($token, ['gpus' => [$this->intel(), $this->gpu()]])->assertOk()->assertJsonPath('status', 'ok');
        $hb = $asset->fresh()->agentHeartbeat;
        $this->assertCount(2, $hb->gpus);
        $this->assertSame('discrete', $hb->gpus[1]['kind']);
        $this->assertSame(6442450944, $hb->gpus[1]['vram_bytes']);

        // agent lama (v9) tidak mengirim gpus: data VGA yang sudah ada tidak terhapus
        $this->withToken($token)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'PC-GPU', 'agent_version' => 9])->assertOk();
        $this->assertCount(2, $asset->fresh()->agentHeartbeat->gpus);

        // jenis tidak dikenal ditolak (konvensi API: HTTP 200 dengan status error)
        $this->report($token, ['gpus' => [$this->gpu(['kind' => 'quantum'])]])->assertOk()->assertJsonPath('status', 'error')->assertJsonStructure(['messages' => ['gpus.0.kind']]);
        // nama wajib; maksimum 8 kartu
        $this->report($token, ['gpus' => [['kind' => 'discrete']]])->assertOk()->assertJsonPath('status', 'error');
        $this->report($token, ['gpus' => array_fill(0, 9, $this->gpu())])->assertOk()->assertJsonPath('status', 'error');
        $this->assertCount(2, $asset->fresh()->agentHeartbeat->gpus, 'laporan yang ditolak tidak mengubah data');
    }

    public function test_gpu_swap_and_driver_update_are_recorded_but_the_first_report_and_unchanged_reports_are_not(): void
    {
        $asset = $this->makeAsset();
        [, $token] = AssetAgentToken::issueFor($asset, null);

        $this->report($token, ['gpus' => [$this->gpu()]])->assertOk();
        $this->assertSame(0, AssetSpecChange::where('asset_id', $asset->id)->count(), 'laporan pertama bukan perubahan');
        $this->report($token, ['gpus' => [$this->gpu()]])->assertOk();
        $this->assertSame(0, AssetSpecChange::where('asset_id', $asset->id)->count(), 'laporan sama tidak dicatat');

        $this->report($token, ['gpus' => [$this->gpu(['driver_version' => '32.0.15.6094'])]])->assertOk();
        $c = AssetSpecChange::where('asset_id', $asset->id)->get();
        $this->assertCount(1, $c);
        $this->assertSame('gpu_driver', $c[0]->field);
        $this->assertStringContainsString('31.0.15.5222', $c[0]->old_value);
        $this->assertStringContainsString('32.0.15.6094', $c[0]->new_value);

        $this->report($token, ['gpus' => [$this->gpu(['name' => 'NVIDIA GeForce RTX 4070 Laptop GPU', 'driver_version' => '32.0.15.6094'])]])->assertOk();
        $swap = AssetSpecChange::where('asset_id', $asset->id)->where('field', 'gpu')->first();
        $this->assertNotNull($swap);
        $this->assertStringContainsString('RTX 4050', $swap->old_value);
        $this->assertStringContainsString('RTX 4070', $swap->new_value);
        $this->assertSame('hardware', $swap->category);
    }

    public function test_monitor_shows_the_gpu_in_the_device_page_overview_distribution_and_asset_card(): void
    {
        $this->actingAs(User::where('email', 'admin@inventaris.test')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $asset = $this->makeAsset();
        [, $token] = AssetAgentToken::issueFor($asset, null);
        $this->report($token, ['gpus' => [$this->intel(), $this->gpu()]])->assertOk();

        $this->get(AgentMonitorResource::getUrl('view', ['record' => $asset]))->assertOk()
            ->assertSee(trans('agent.section_gpu'))->assertSee('RTX 4050')->assertSee('31.0.15.5222')->assertSee('1920x1080 @ 144 Hz')->assertSee(trans('agent.gpu_kind_discrete'));

        $dist = app(AgentFleetMonitor::class)->build()['distributions'];
        $this->assertSame(1, $dist['gpu']['NVIDIA GeForce RTX 4050 Laptop GPU'] ?? null, 'satu hitungan per perangkat: kartu diskrit diutamakan');
        $this->assertArrayNotHasKey('Intel UHD Graphics', $dist['gpu']);

        $this->get(AgentMonitorResource::getUrl())->assertOk()->assertSee('VGA');
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $asset]))->assertOk()->assertSee('RTX 4050');

        // perangkat tanpa data VGA (agent lama) menampilkan petunjuk, bukan error
        $old = $this->makeAsset();
        [, $oldToken] = AssetAgentToken::issueFor($old, null);
        $this->withToken($oldToken)->postJson('/api/agent/v1/heartbeat', ['hostname' => 'PC-OLD', 'agent_version' => 9])->assertOk();
        $this->get(AgentMonitorResource::getUrl('view', ['record' => $old]))->assertOk()->assertSee(trans('agent.gpu_none'));
    }
}
