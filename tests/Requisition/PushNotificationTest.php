<?php

namespace Tests\Requisition;

use App\Models\ITRequest;
use App\Models\User;
use App\Notifications\NewRequestSubmitted;
use App\Notifications\RequestStatusUpdated;
use Illuminate\Support\Facades\Notification;
use Tests\RequisitionTestCase;

class PushNotificationTest extends RequisitionTestCase
{

    public function test_admin_can_subscribe_to_push_notifications(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin)->postJson('/requisition/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint-123',
            'keys' => [
                'p256dh' => 'fake-p256dh-key',
                'auth' => 'fake-auth-token',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint-123',
            'subscribable_id' => $admin->id,
        ]);
    }

    public function test_admin_can_unsubscribe_from_push_notifications(): void
    {
        $admin = $this->makeUser('admin');
        $admin->updatePushSubscription('https://fcm.googleapis.com/fcm/send/fake-endpoint-456', 'key', 'token');

        $response = $this->actingAs($admin)->postJson('/requisition/push/unsubscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint-456',
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('push_subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint-456',
        ]);
    }

    public function test_user_role_can_subscribe_to_push_notifications(): void
    {
        // Role user boleh subscribe supaya bisa dapat notifikasi saat status request miliknya berubah
        // (beda dari notifikasi "Request Baru Masuk" yang cuma dikirim ke admin/super_admin).
        $user = $this->makeUser('user');

        $response = $this->actingAs($user)->postJson('/requisition/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint-789',
            'keys' => [
                'p256dh' => 'fake-p256dh-key',
                'auth' => 'fake-auth-token',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint-789',
            'subscribable_id' => $user->id,
        ]);
    }

    public function test_user_role_can_unsubscribe_a_leftover_subscription(): void
    {
        // Skenario: akun dulunya admin (subscribe notifikasi), lalu diturunkan ke role user -
        // dia tetap harus bisa membersihkan subscription lamanya sendiri.
        $user = $this->makeUser('user');
        $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/leftover-endpoint', 'key', 'token');

        $response = $this->actingAs($user)->postJson('/requisition/push/unsubscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/leftover-endpoint',
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('push_subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/leftover-endpoint',
        ]);
    }

    public function test_submitting_a_request_notifies_all_admins_and_super_admins(): void
    {
        Notification::fake();

        $submitter = $this->makeUser('admin');
        $otherAdmin = $this->makeUser('admin');
        $superAdmin = $this->makeUser('super_admin');
        $regularUser = $this->makeUser('user');

        $this->actingAs($submitter)->post('/requisition/requests', [
            'type' => 'ITR',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Laptop Baru',
            'items' => [['name' => 'Laptop', 'qty' => 1]],
            'description' => 'Karyawan baru butuh laptop',
        ]);

        Notification::assertSentTo($submitter, NewRequestSubmitted::class);
        Notification::assertSentTo($otherAdmin, NewRequestSubmitted::class);
        Notification::assertSentTo($superAdmin, NewRequestSubmitted::class);
        Notification::assertNotSentTo($regularUser, NewRequestSubmitted::class);
    }

    public function test_updating_status_notifies_the_requests_owner(): void
    {
        Notification::fake();

        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->create(['user_id' => $owner->id, 'status' => 'Pending']);

        $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'Approved']);

        Notification::assertSentTo($owner, RequestStatusUpdated::class);
    }

    public function test_updating_status_does_not_error_when_request_has_no_owner_account(): void
    {
        Notification::fake();

        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['user_id' => null, 'status' => 'Pending']);

        $response = $this->actingAs($admin)->patch("/requisition/requests/{$item->id}/status", ['status' => 'Approved']);

        $response->assertSessionHasNoErrors();
    }

    public function test_resubmitting_a_rejected_request_notifies_admins_again(): void
    {
        Notification::fake();

        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->create([
            'type' => 'ITR',
            'user_id' => $owner->id,
            'status' => 'Rejected',
        ]);

        $this->actingAs($owner)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Budi Santoso Revisi',
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Laptop Baru (Revisi)',
            'items' => [['name' => 'Laptop', 'qty' => 1]],
            'description' => 'Diperbaiki sesuai catatan IT',
        ]);

        Notification::assertSentTo($admin, NewRequestSubmitted::class);
    }

    public function test_admin_editing_a_non_rejected_request_does_not_resend_the_new_request_notification(): void
    {
        Notification::fake();

        $admin = $this->makeUser('admin');
        $item = ITRequest::factory()->create(['type' => 'ITR', 'status' => 'Approved']);

        $this->actingAs($admin)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Diperbaiki Admin',
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Laptop',
            'items' => [['name' => 'Laptop', 'qty' => 1]],
            'description' => 'test',
        ]);

        Notification::assertNotSentTo($admin, NewRequestSubmitted::class);
    }
}
