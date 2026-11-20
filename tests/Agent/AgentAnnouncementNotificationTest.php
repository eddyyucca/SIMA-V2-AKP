<?php

namespace Tests\Agent;

use App\Filament\Resources\AgentAnnouncements\AgentAnnouncementResource;
use App\Filament\Resources\AgentAnnouncements\Pages\ManageAgentAnnouncements;
use App\Models\AgentAnnouncement;
use App\Models\AssetAgentToken;
use App\Models\User;
use App\Services\AgentAdminService;
use Livewire\Livewire;
use Tests\AgentTestCase;

/** Pengumuman agent: notifikasi lonceng saat diterbitkan + umpan balik (toast) saat dibuat, dihentikan, dihapus. */
class AgentAnnouncementNotificationTest extends AgentTestCase
{
    public function test_publishing_notifies_admins_and_it_staff_in_the_bell(): void
    {
        $a1 = $this->makeAsset(); AssetAgentToken::issueFor($a1, null);
        $a2 = $this->makeAsset(); AssetAgentToken::issueFor($a2, null);
        $admin = User::where('email', 'admin@inventaris.test')->firstOrFail();
        $staff = User::where('email', 'staff@inventaris.test')->firstOrFail();
        $plain = User::where('email', 'user@inventaris.test')->firstOrFail();

        $ann = app(AgentAdminService::class)->createAnnouncement(['title' => 'Update keamanan wajib', 'body' => 'Mohon restart laptop.', 'severity' => 'important',
            'require_ack' => true, 'target_mode' => 'all'], $admin);

        foreach ([$admin, $staff] as $u) {
            $n = $u->notifications()->get()->first(fn ($x) => str_contains(json_encode($x->data), 'Update keamanan wajib'));
            $this->assertNotNull($n, "{$u->email} harus menerima notifikasi");
            $this->assertStringContainsString('Update keamanan wajib', json_encode($n->data));
            $this->assertStringContainsString('Wajib dikonfirmasi', json_encode($n->data));
        }
        $this->assertNull($plain->notifications()->get()->first(fn ($x) => str_contains(json_encode($x->data), 'Update keamanan wajib')), 'pengguna biasa tidak diberi tahu');
        $this->assertNotNull($ann->id);
    }

    public function test_create_stop_and_delete_give_feedback_to_the_user(): void
    {
        $asset = $this->makeAsset(); AssetAgentToken::issueFor($asset, null);
        $admin = User::where('email', 'admin@inventaris.test')->firstOrFail();

        $t = Livewire::actingAs($admin)->test(ManageAgentAnnouncements::class);
        $t->callAction('create', ['title' => 'Info', 'body' => 'Isi pengumuman', 'severity' => 'info', 'target_mode' => 'all'])->assertHasNoActionErrors()->assertNotified(trans('agent.ann_created'));
        $ann = AgentAnnouncement::latest('id')->firstOrFail();

        $t->callTableAction('stop', $ann)->assertNotified(trans('agent.ann_stopped'));
        $t->callTableAction('hapus', $ann)->assertNotified(trans('agent.ann_deleted'));
        $this->assertNull(AgentAnnouncement::find($ann->id));
    }
}
