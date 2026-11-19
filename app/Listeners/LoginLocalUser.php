<?php

namespace App\Listeners;

use Akp\PortalSso\Events\PortalUserLoggedIn;
use App\Services\PortalUserSync;
use Illuminate\Support\Facades\Auth;

class LoginLocalUser
{
    public function handle(PortalUserLoggedIn $event): void
    {
        $user = app(PortalUserSync::class)->resolve($event->user);   // melempar LoginDenied -> halaman 403, sesi Portal diakhiri paket
        Auth::guard('web')->login($user);
        session()->forget('sso.local');
    }
}
