<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use RuntimeException;
use ZipArchive;

/**
 * Paket pasang SIMA Asset Agent untuk satu laptop, dibuat langsung dari halaman detail (tanpa file server / flashdisk):
 * agent.ps1 + agent-update.ps1 dari rilis bertanda tangan terbaru, install.ps1 / uninstall.ps1, config.json berisi token BARU
 * untuk aset tersebut, dan Pasang-Agent.cmd (minta hak Administrator, menyalin ke Program Files, mengunci config.json, memasang task).
 * Membuat paket = membuat token baru (token lama aset itu dicabut).
 */
class AgentInstallerPackage
{
    public const DIR = 'SIMA Asset Agent';

    public function __construct(private AgentAdminService $admin, private AgentUpdateManifest $manifest) {}

    /** @return array{path: string, filename: string} berkas ZIP sementara; hapus setelah dikirim */
    public function build(Asset $asset, ?User $by): array
    {
        $m = $this->manifest->read();
        $release = $m ? $this->manifest->dir() . '/' . $m['file'] : null;
        if (! $release || ! is_file($release)) throw new RuntimeException('Belum ada rilis agent di server.');

        $client = base_path('agent-client');
        foreach (['install.ps1', 'uninstall.ps1'] as $f) if (! is_file("$client/$f")) throw new RuntimeException("Berkas $f tidak ditemukan di server.");

        $src = new ZipArchive();
        if ($src->open($release) !== true) throw new RuntimeException('Rilis agent tidak bisa dibaca.');
        $files = [];
        foreach (['agent.ps1', 'agent-update.ps1'] as $f) {
            $c = $src->getFromName($f);
            if ($c === false) throw new RuntimeException("Rilis agent tidak memuat $f.");
            $files[$f] = $c;
        }
        $src->close();
        $files['install.ps1'] = file_get_contents("$client/install.ps1");
        $files['uninstall.ps1'] = file_get_contents("$client/uninstall.ps1");

        $token = $this->admin->issueToken($asset, $by);
        $files['config.json'] = json_encode(['simaUrl' => rtrim((string) config('app.url'), '/'), 'token' => $token], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $files['Pasang-Agent.cmd'] = $this->launcher();
        $files['BACA-DULU.txt'] = $this->readme($asset);

        $path = tempnam(sys_get_temp_dir(), 'sima-agent-');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Tidak bisa membuat paket.');
        foreach ($files as $name => $content) $zip->addFromString($name, $content);
        $zip->close();

        return ['path' => $path, 'filename' => 'SIMA-Agent-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $asset->asset_tag) . '.zip'];
    }

    private function launcher(): string
    {
        $dir = self::DIR;
        $bat = <<<BAT
@echo off
setlocal
rem Pasang SIMA Asset Agent (minta hak Administrator bila belum).
net session >nul 2>&1
if %errorlevel% neq 0 (
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
set "SRC=%~dp0"
set "DST=%ProgramFiles%\\{$dir}"
if not exist "%DST%" mkdir "%DST%"
copy /Y "%SRC%agent.ps1" "%DST%\\" >nul
copy /Y "%SRC%agent-update.ps1" "%DST%\\" >nul
copy /Y "%SRC%install.ps1" "%DST%\\" >nul
copy /Y "%SRC%uninstall.ps1" "%DST%\\" >nul
copy /Y "%SRC%config.json" "%DST%\\" >nul
rem config.json berisi token: hanya SYSTEM dan Administrators.
icacls "%DST%\\config.json" /inheritance:r /grant:r "*S-1-5-18:F" "*S-1-5-32-544:F" >nul
powershell -NoProfile -ExecutionPolicy Bypass -File "%DST%\\install.ps1"
if %errorlevel% neq 0 (echo. & echo GAGAL memasang agent. Foto layar ini dan kirim ke IT. & pause & exit /b 1)
del /F /Q "%SRC%config.json" >nul 2>&1
echo.
echo Selesai. Laptop ini akan muncul online di SIMA dalam beberapa menit. Berkas config.json di folder unduhan sudah dihapus.
pause
BAT;
        return str_replace("\n", "\r\n", $bat);
    }

    private function readme(Asset $asset): string
    {
        return "Paket pasang SIMA Asset Agent untuk aset {$asset->asset_tag}\r\n\r\n"
            . "1. Ekstrak ZIP ini di laptop tujuan (bukan di server).\r\n"
            . "2. Klik dua kali Pasang-Agent.cmd, pilih Yes pada permintaan Administrator.\r\n"
            . "3. Tunggu 'Selesai'. Laptop tampil online di SIMA dalam beberapa menit.\r\n\r\n"
            . "PENTING: paket ini berisi token khusus untuk {$asset->asset_tag}. Jangan dipakai di laptop lain dan jangan dibagikan; hapus ZIP setelah selesai.\r\n";
    }
}
