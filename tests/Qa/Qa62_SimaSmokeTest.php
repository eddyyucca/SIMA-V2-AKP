<?php

namespace Tests\Qa;

use Spatie\Permission\Models\Role;
use Tests\QaTestCase;

class Qa62_SimaSmokeTest extends QaTestCase
{
    public function test_smoke_passes_on_a_healthy_install_and_warns_about_demo_accounts(): void
    {
        $this->artisan('sima:smoke', ['--skip-network' => true])
            ->expectsOutputToContain('Tidak ada akun demo')
            ->expectsOutputToContain('PERINGATAN')
            ->assertSuccessful();
    }

    public function test_smoke_fails_when_roles_are_missing(): void
    {
        Role::where('name', 'Staff IT')->delete();
        $this->artisan('sima:smoke', ['--skip-network' => true])->assertFailed();
    }

    public function test_smoke_fails_on_unconfigured_sso(): void
    {
        config(['sso.enabled' => true, 'sso.secret' => null]);
        $this->artisan('sima:smoke', ['--skip-network' => true])->assertFailed();
    }
}
