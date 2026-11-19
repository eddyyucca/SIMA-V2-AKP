<?php

namespace App\Http\Middleware;

use App\Models\AssetHelperToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Otentikasi SIMA Agent Helper (program di sesi pengguna). Token ini punya cakupan sempit: hanya pengumuman dan chat satu
 * aset (lihat AssetHelperToken). Ditolak juga bila token agent aset itu sudah dicabut, jadi mencabut token agent langsung
 * memutus program pembantunya.
 */
class AssetHelperTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = AssetHelperToken::findValid((string) $request->bearerToken());
        $asset = $token?->asset;

        if (! $asset || ! $asset->agentTokens()->whereNull('revoked_at')->exists()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->attributes->set('helper_asset', $asset);

        return $next($request);
    }
}
