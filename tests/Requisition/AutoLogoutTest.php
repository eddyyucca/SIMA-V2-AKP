<?php

namespace Tests\Requisition;

use App\Models\User;
use Tests\RequisitionTestCase;

class AutoLogoutTest extends RequisitionTestCase
{

    public function test_user_role_gets_the_idle_auto_logout_script_on_a_shared_layout_page(): void
    {
        $user = $this->makeUser('user');

        $response = $this->actingAs($user)->get('/requisition/requests/create');

        $response->assertOk();
        $response->assertSee('IDLE_LIMIT_MS', false);
    }

    public function test_admin_role_does_not_get_the_idle_auto_logout_script(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin)->get('/requisition/requests/create');

        $response->assertOk();
        $response->assertDontSee('IDLE_LIMIT_MS', false);
    }

    public function test_super_admin_role_does_not_get_the_idle_auto_logout_script(): void
    {
        $superAdmin = $this->makeUser('super_admin');

        $response = $this->actingAs($superAdmin)->get('/requisition/requests/create');

        $response->assertOk();
        $response->assertDontSee('IDLE_LIMIT_MS', false);
    }

    public function test_user_role_gets_the_idle_auto_logout_script_on_the_create_request_page(): void
    {
        $user = $this->makeUser('user');

        $response = $this->actingAs($user)->get('/requisition/requests/create');

        $response->assertOk();
        $response->assertSee('IDLE_LIMIT_MS', false);
    }
}
