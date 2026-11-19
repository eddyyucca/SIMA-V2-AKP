<?php

namespace App\Services;

/**
 * Membaca manifest rilis SIMA Asset Agent (agent-updates/manifest.json + agent-N.zip), yang dibuat DAN ditandatangani
 * oleh agent-installer/tools/publish-agent-update.ps1 (kunci privat hanya ada di PC admin IT, bukan di server).
 * Dipakai endpoint update agent dan halaman pemantauan armada.
 */
class AgentUpdateManifest
{
    public function dir(): string
    {
        return rtrim((string) config('agent.updates_path', base_path('agent-updates')), '/'.DIRECTORY_SEPARATOR);
    }

    /** @return array<string, mixed>|null null bila tidak ada rilis atau manifest tidak valid */
    public function read(): ?array
    {
        $path = $this->dir().'/manifest.json';
        if (! is_file($path)) {
            return null;
        }

        // ltrim BOM UTF-8: manifest yang ditulis PowerShell 5.1 bisa berawalan BOM, dan json_decode menolaknya.
        $m = json_decode(ltrim((string) file_get_contents($path), "\xEF\xBB\xBF"), true);

        if (! is_array($m)
            || ! isset($m['version'], $m['file'], $m['sha256'], $m['size'], $m['signature'])
            || ! is_int($m['version'])
            || ! preg_match('/^agent-\d+\.zip$/', (string) $m['file'])) {
            return null;
        }

        return $m;
    }

    public function latestVersion(): ?int
    {
        return $this->read()['version'] ?? null;
    }
}
