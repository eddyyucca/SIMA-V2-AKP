<?php

namespace App\Http\Middleware;

use Akp\PortalSso\PortalSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saat SSO aktif: sesi lokal hanya sah selama sesi Portal-nya sah (umur maksimal config sso.max_session_minutes, lalu Portal memeriksa ulang akses).
 * Login lokal darurat (sso.local) dikecualikan dan hanya bisa dibuka bila sso.allow_local_login menyala.
 */
class EnforcePortalSession
{
    public function __construct(private PortalSession $portal) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('sso.enabled') || ! Auth::check()) return $next($request);

        if ($request->session()->get('sso.local')) {
            if (config('sso.allow_local_login')) return $next($request);
        } elseif ($this->portal->user() !== null) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        if ($request->expectsJson()) abort(401, 'Sesi berakhir.');
        $request->session()->put('portal.intended', $request->isMethod('GET') ? $request->fullUrl() : url('/admin'));

        return redirect()->away(portal_sso_url() . '/login?' . http_build_query(['app' => config('sso.app_code')]));
    }
}
