<?php

namespace App\Http\Middleware;

use App\Models\AssetAgentToken as AssetAgentTokenModel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token SIMA Asset Agent: satu token per aset (lihat AssetAgentToken). Beda dengan App\Http\Middleware\IntegrationToken
 * (satu rahasia untuk satu aplikasi lain) — di sini tiap laptop punya tokennya sendiri, dicabut per-aset.
 */
class AssetAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = AssetAgentTokenModel::findActiveMatching((string) $request->bearerToken());

        if (! $token) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // agent sudah memakai tokennya = sudah terpasang: token asli tidak ditampilkan lagi
        if ($token->plain_token !== null) {
            $token->forceFill(['plain_token' => null])->saveQuietly();
        }

        $request->attributes->set('agent_asset', $token->asset);

        return $next($request);
    }
}
