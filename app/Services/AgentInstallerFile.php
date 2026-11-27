<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use RuntimeException;

/**
 * Berkas pasang SIMA Asset Agent untuk Tim Support: HANYA installer .exe (agent-client/SIMA-Asset-Agent-Setup.exe), tanpa ZIP.
 * Installer meminta alamat server dan token agent saat dijalankan; token dibuat di sini bila aset belum punya token aktif dan
 * ditampilkan di halaman sampai agent terpasang (lihat AssetAgentToken::pendingPlain). Token yang sudah ada tidak diganti.
 */
class AgentInstallerFile
{
    public const FILENAME = 'SIMA-Asset-Agent-Setup.exe';

    public function __construct(private AgentAdminService $admin) {}

    public function path(): string
    {
        $p = base_path('agent-client/' . self::FILENAME);
        if (! is_file($p)) throw new RuntimeException('Berkas installer agent belum tersedia di server.');

        return $p;
    }

    /** Pastikan aset punya token aktif; kembalikan true bila token baru dibuat. */
    public function ensureToken(Asset $asset, ?User $by): bool
    {
        if ($asset->agentTokens()->whereNull('revoked_at')->exists()) return false;
        $this->admin->issueToken($asset, $by);

        return true;
    }
}
