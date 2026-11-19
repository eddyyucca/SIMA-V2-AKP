<?php

namespace Tests\Requisition;

use App\Models\ITRequest;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\RequisitionTestCase;

class TelegramNotificationTest extends RequisitionTestCase
{

    public function test_submitting_a_request_notifies_the_telegram_group_once(): void
    {
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.chat_id' => '-100123456789']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = $this->makeUser('user');

        $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'ITR',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'department' => 'IT',
            'position' => 'Staff',
            'title' => 'Laptop Baru',
            'items' => [['name' => 'Laptop', 'qty' => 1]],
            'description' => 'Untuk kebutuhan kerja.',
        ]);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
                && $request['chat_id'] === '-100123456789'
                && str_contains($request['text'], 'Budi Santoso')
                && str_contains($request['text'], 'Laptop Baru');
        });
    }

    public function test_resubmitting_a_rejected_request_notifies_the_telegram_group_once(): void
    {
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.chat_id' => '-100123456789']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $owner = $this->makeUser('user');
        $item = ITRequest::factory()->create(['type' => 'ITR', 'user_id' => $owner->id, 'status' => 'Rejected']);

        $this->actingAs($owner)->patch("/requisition/requests/{$item->id}", [
            'employee_name' => 'Budi Santoso Revisi',
            'employee_nik' => '123456',
            'position' => 'Staff',
            'title' => 'Laptop Baru (Revisi)',
            'items' => [['name' => 'Laptop', 'qty' => 1]],
            'description' => 'Diperbaiki sesuai catatan IT',
        ]);

        Http::assertSentCount(1);
    }

    public function test_no_telegram_call_is_made_when_bot_token_is_not_configured(): void
    {
        config(['services.telegram.bot_token' => null, 'services.telegram.chat_id' => null]);
        Http::fake();

        $user = $this->makeUser('user');

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'ITR',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'department' => 'IT',
            'position' => 'Staff',
            'title' => 'Laptop Baru',
            'items' => [['name' => 'Laptop', 'qty' => 1]],
            'description' => 'Untuk kebutuhan kerja.',
        ]);

        $response->assertSessionHasNoErrors();
        Http::assertNothingSent();
    }

    public function test_a_failed_telegram_api_response_does_not_break_the_submission(): void
    {
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.chat_id' => '-100123456789']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

        $user = $this->makeUser('user');

        $response = $this->actingAs($user)->post('/requisition/requests', [
            'type' => 'ITR',
            'employee_name' => 'Budi Santoso', 'employee_id' => $this->requesterId(),
            'employee_nik' => '123456',
            'department' => 'IT',
            'position' => 'Staff',
            'title' => 'Laptop Baru',
            'items' => [['name' => 'Laptop', 'qty' => 1]],
            'description' => 'Untuk kebutuhan kerja.',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('i_t_requests', ['requester_name' => 'Budi Santoso']);
    }
}
