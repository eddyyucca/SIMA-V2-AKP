<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Token layanan untuk aplikasi AKPSatu lain yang membaca aplikasi ini (SIMA_API_TOKEN). Kosong = API integrasi mati. */
class IntegrationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.integration_token');
        $given = (string) $request->bearerToken();

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
