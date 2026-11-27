<?php

namespace Tests\Agent;

use App\Filament\Clusters\Agent\Pages\AgentAlerts;
use App\Filament\Clusters\Agent\Pages\AgentCompliance;
use App\Filament\Clusters\Agent\Pages\AgentRollout;
use App\Filament\Resources\AgentMonitor\AgentMonitorResource;
use App\Filament\Resources\AgentMonitor\Pages\ListAgentMonitor;
use App\Filament\Resources\AgentMonitor\Pages\ViewAgentDevice;
use App\Models\AgentRolloutSnapshot;
use App\Models\Asset;
use App\Models\AssetAgentToken;
use App\Models\AssetHeartbeat;
use App\Models\AssetSpecChange;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\AgentTestCase;

/** Halaman menu SIMA Agent (ikhtisar, rollout, kepatuhan, peringatan, detail perangkat) + ekspor CSV, dengan data heartbeat nyata. */
class AgentUiPagesTest extends AgentTestCase
{
    private Asset $good;
    private Asset $bad;
    private Asset $tokenOnly;
    private Asset $missing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', 'admin@inventaris.test')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->good = $this->makeAsset();
        $this->good->update(['agent_status' => 'online', 'agent_last_seen' => now()]);
        $this->hb($this->good, ['hostname' => 'PC-OK', 'os_version' => 'Microsoft Windows 11 Pro', 'bitlocker_status' => 'on', 'defender_enabled' => true,
            'defender_signature_at' => now(), 'last_patch_at' => now()->subDays(3), 'ram_total_bytes' => 17179869184, 'ram_free_bytes' => 8589934592,
            'agent_version' => 9, 'usb_storage' => 'blocked', 'local_admins' => [['name' => 'Administrator', 'type' => 'User']]]);

        $this->bad = $this->makeAsset();
        $this->bad->update(['agent_status' => 'offline', 'agent_last_seen' => now()->subDays(5)]);
        $this->hb($this->bad, ['hostname' => 'PC-BAD', 'os_version' => 'Microsoft Windows 11 Home', 'bitlocker_status' => 'off', 'defender_enabled' => false,
            'ram_total_bytes' => 4294967296, 'ram_free_bytes' => 1073741824, 'agent_version' => 7, 'builtin_admin_enabled' => true,
            'local_admins' => [['name' => 'Administrator', 'type' => 'User'], ['name' => 'budi.harian', 'type' => 'User']],
            'disks' => [['drive' => 'C:', 'total' => 500000000000, 'free' => 10000000000]], 'software' => [['name' => 'Chrome', 'version' => '120', 'publisher' => 'Google']]]);
        AssetSpecChange::create(['asset_id' => $this->bad->id, 'category' => 'hardware', 'field' => 'ram_total_bytes', 'old_value' => '8 GB', 'new_value' => '4 GB', 'changed_at' => now()]);

        $this->tokenOnly = $this->makeAsset();
        AssetAgentToken::issueFor($this->tokenOnly, null);
        $this->missing = $this->makeAsset();
    }

    private function hb(Asset $a, array $attrs): AssetHeartbeat
    {
        return AssetHeartbeat::create($attrs + ['asset_id' => $a->id, 'reported_at' => now()]);
    }

    public function test_overview_lists_devices_with_fleet_kpis_and_status_tabs(): void
    {
        $t = Livewire::test(ListAgentMonitor::class)->assertSuccessful()
            ->set('tableRecordsPerPage', 100)
            ->assertCanSeeTableRecords([$this->good, $this->bad, $this->tokenOnly, $this->missing]);   // laptop yang belum terpasang agent kini digabung di tabel utama
        // label status terbaca (bug lama: closure ber-parameter $s tidak mendapat state dari Filament sehingga label kosong dan warna selalu abu-abu)
        $t->assertTableColumnFormattedStateSet('agent_status', 'Online', $this->good)->assertTableColumnFormattedStateSet('agent_status', 'Offline', $this->bad);
        $t->set('activeTab', 'offline')->assertCanSeeTableRecords([$this->bad])->assertCanNotSeeTableRecords([$this->good]);
        $t->set('activeTab', 'online')->assertCanSeeTableRecords([$this->good])->assertCanNotSeeTableRecords([$this->bad]);
        $t->set('activeTab', 'belum')->assertCanSeeTableRecords([$this->tokenOnly])->assertCanNotSeeTableRecords([$this->good]);

        $html = $this->get(AgentMonitorResource::getUrl())->assertOk()->getContent();
        $this->assertStringContainsString(trans('agent.kpi_total'), $html);
        $this->assertStringContainsString(trans('agent.kpi_compliance'), $html);
    }

    public function test_device_detail_shows_every_section_and_exposes_the_agent_actions(): void
    {
        $this->get(AgentMonitorResource::getUrl('view', ['record' => $this->bad]))->assertOk()
            ->assertSee('PC-BAD')->assertSee('Chrome')->assertSee('budi.harian')->assertSee('BitLocker')->assertSee('4 GB');
        Livewire::test(ViewAgentDevice::class, ['record' => $this->bad->id])->assertSuccessful()
            ->assertActionExists('agent_token')->assertActionExists('agent_command');
        // perangkat yang belum pernah lapor tetap bisa dibuka (tanpa data)
        $this->get(AgentMonitorResource::getUrl('view', ['record' => $this->tokenOnly]))->assertOk()->assertSee(trans('agent.no_data'));
    }

    public function test_rollout_counts_laptops_installed_token_only_and_missing_and_records_a_daily_snapshot(): void
    {
        $r = app(\App\Services\AgentRolloutReport::class)->build();
        $this->assertGreaterThanOrEqual(4, $r['total']);
        $this->assertGreaterThanOrEqual(2, $r['installed']);
        $this->assertGreaterThanOrEqual(1, $r['token_only']);
        $this->assertContains($this->missing->id, $r['pending_all']->pluck('asset.id')->all());
        $this->assertContains($this->tokenOnly->id, $r['pending_all']->pluck('asset.id')->all());
        $this->assertNotContains($this->good->id, $r['pending_all']->pluck('asset.id')->all());

        $this->get(AgentRollout::getUrl())->assertOk()->assertSee(trans('agent.rollout_hero'))->assertSee('Buka tabel Ikhtisar');

        $this->artisan('agent:rollout-snapshot')->assertSuccessful();
        $this->artisan('agent:rollout-snapshot')->assertSuccessful();
        $this->assertSame(1, AgentRolloutSnapshot::where('snapshot_date', now()->toDateString())->count(), 'satu baris per hari');
    }

    public function test_compliance_reports_editions_exposed_admins_and_security_state(): void
    {
        $c = app(\App\Services\AgentComplianceReport::class)->build();
        $this->assertSame(1, $c['home']);
        $this->assertSame(1, $c['bitlocker']['off']);
        $this->assertSame(1, $c['defender']['off']);
        $this->assertCount(1, $c['admin_exposed']);
        $this->assertSame(['budi.harian'], array_values($c['admin_exposed'][0]['names']));

        $this->get(AgentCompliance::getUrl())->assertOk()->assertSee('budi.harian')->assertSee('PC-BAD');
    }

    public function test_alert_report_groups_by_severity_and_exports_csv_safely(): void
    {
        $this->get(AgentAlerts::getUrl())->assertOk();
        $this->get(AgentAlerts::getUrl() . '?tingkat=critical')->assertOk()->assertSee($this->bad->asset_tag);

        $csv = $this->get(route('agent.export.alerts'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString('Tag aset', $csv);
        $this->assertStringContainsString($this->bad->asset_tag, $csv);
        $only = $this->get(route('agent.export.alerts', ['tingkat' => 'critical']))->assertOk()->streamedContent();
        $this->assertStringNotContainsString($this->good->asset_tag, $only);

        // nilai berawalan = + - @ diberi apostrof supaya tidak menjadi rumus Excel
        $this->assertSame("'=1+1", \App\Services\AgentAlertReport::safe('=1+1'));

        $rollout = $this->get(route('agent.export.rollout'))->assertOk()->streamedContent();
        $this->assertStringContainsString($this->missing->asset_tag, $rollout);
        $this->assertStringNotContainsString($this->good->asset_tag, $rollout);
    }

    public function test_pages_and_exports_are_closed_to_users_without_inventory_permission(): void
    {
        $this->actingAs(User::where('email', 'user@inventaris.test')->firstOrFail());
        $this->get(route('agent.export.alerts'))->assertForbidden();
        $this->get(route('agent.export.rollout'))->assertForbidden();
        $this->get(AgentRollout::getUrl())->assertForbidden();
    }

    public function test_old_sima_agent_urls_redirect_to_the_new_pages(): void
    {
        $this->get('/agent-monitor')->assertRedirect(AgentMonitorResource::getUrl());
        $this->get('/agent-monitor/rollout')->assertRedirect(AgentRollout::getUrl());
        $this->get('/agent-monitor/compliance')->assertRedirect(AgentCompliance::getUrl());
        $this->get('/agent-monitor/reports')->assertRedirect(AgentAlerts::getUrl());
    }

    public function test_agent_download_gives_only_the_exe_and_makes_a_token_when_missing(): void
    {
        $this->assertFileExists(base_path('agent-client/' . \App\Services\AgentInstallerFile::FILENAME));

        // laptop yang belum terpasang: token dibuat dan tampil di halaman; berkas yang diunduh hanya .exe
        $this->assertSame(0, $this->missing->agentTokens()->whereNull('revoked_at')->count());
        Livewire::test(ListAgentMonitor::class)->set('tableRecordsPerPage', 100)
            ->callTableAction('agent_download', $this->missing)->assertHasNoTableActionErrors()
            ->assertFileDownloaded('SIMA-Asset-Agent-Setup.exe');
        $this->assertSame(1, $this->missing->agentTokens()->whereNull('revoked_at')->count());
        $this->assertNotNull(AssetAgentToken::pendingPlain($this->missing));

        // token yang sudah ada tidak diganti
        $before = $this->tokenOnly->agentTokens()->whereNull('revoked_at')->value('id');
        Livewire::test(ListAgentMonitor::class)->set('tableRecordsPerPage', 100)->callTableAction('agent_download', $this->tokenOnly)->assertFileDownloaded('SIMA-Asset-Agent-Setup.exe');
        $this->assertSame($before, $this->tokenOnly->agentTokens()->whereNull('revoked_at')->value('id'));

        Livewire::test(ViewAgentDevice::class, ['record' => $this->good->id])->assertActionVisible('agent_download');
    }
}
