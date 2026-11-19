<?php

namespace App\Http\Middleware;

use App\Filament\Pages\ChangePassword;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Akun dengan password awal (NIK) hanya boleh membuka halaman Ganti Password dan logout sampai passwordnya diganti. */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->must_change_password && ! $request->routeIs('filament.admin.pages.ganti-password', 'filament.admin.auth.logout', 'logout')) {
            return redirect(ChangePassword::getUrl());
        }

        return $next($request);
    }
}
