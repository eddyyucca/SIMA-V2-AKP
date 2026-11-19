<?php

namespace App\Listeners;

use Akp\PortalSso\Events\PortalUserLoggingOut;
use Illuminate\Support\Facades\Auth;

class LogoutLocalUser
{
    public function handle(PortalUserLoggingOut $event): void
    {
        Auth::guard('web')->logout();
    }
}
