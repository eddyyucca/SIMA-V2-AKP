<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetAgentCommand;
use App\Models\AssetHeartbeat;
use App\Models\AssetHelperToken;
use App\Services\AgentUpdateManifest;
use App\Services\AssetSpecChangeRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint untuk SIMA Asset Agent (skrip PowerShell terpasang di laptop/PC). Lihat docs/rencana-agent-monitoring-aset.md.
 * Hanya menerima fakta perangkat (hardware & kepatuhan IT dasar): hostname, IP, OS, disk, CPU/RAM, status
 * BitLocker/Defender/patch. TIDAK ADA data personal/sensitif: tidak ada keylog, screenshot, riwayat
 * browsing, isi file, atau lokasi presisi.
 */
class AssetAgentController extends Controller
{
    /** Versi skrip agen bila belum ada paket rilis (agent-updates/manifest.json). */
    private const CURRENT_AGENT_VERSION = 9;

    /**
     * Manifest rilis agent (dibuat dan DITANDATANGANI oleh agent-installer/tools/publish-agent-update.ps1; kunci privat
     * hanya ada di PC admin IT). Server hanya menyajikan berkas; keaslian diperiksa agent dengan kunci publiknya.
     */
    private function manifest(): ?array
    {
        return app(AgentUpdateManifest::class)->read();
    }

    private function updatesDir(): string
    {
        return app(AgentUpdateManifest::class)->dir();
    }

    public function heartbeat(Request $request): JsonResponse
    {
        /** @var Asset $asset */
        $asset = $request->attributes->get('agent_asset');

        $data = $request->validate([
            'hostname' => ['nullable', 'string', 'max:191'],
            'ip_address' => ['nullable', 'ip'],
            'os_version' => ['nullable', 'string', 'max:191'],
            'disk_free_bytes' => ['nullable', 'integer', 'min:0'],
            'agent_version' => ['nullable', 'integer', 'min:0'],
            // Hardware
            'manufacturer' => ['nullable', 'string', 'max:191'],
            'model' => ['nullable', 'string', 'max:191'],
            'serial_number' => ['nullable', 'string', 'max:191'],
            'cpu' => ['nullable', 'string', 'max:191'],
            'ram_total_bytes' => ['nullable', 'integer', 'min:0'],
            'disk_total_bytes' => ['nullable', 'integer', 'min:0'],
            'os_build' => ['nullable', 'string', 'max:50'],
            'os_architecture' => ['nullable', 'string', 'max:20'],
            'domain' => ['nullable', 'string', 'max:191'],
            'last_boot_at' => ['nullable', 'date'],
            // Kepatuhan IT
            'bitlocker_status' => ['nullable', 'string', 'in:on,off,unknown'],
            'defender_enabled' => ['nullable', 'boolean'],
            'defender_signature_at' => ['nullable', 'date'],
            'last_patch_at' => ['nullable', 'date'],
            // true = berkas token program pembantu hilang/kedaluwarsa di laptop: minta dibuatkan yang baru
            'helper_missing' => ['nullable', 'boolean'],
            // Jaringan
            'network_type' => ['nullable', 'string', 'in:wifi,ethernet,other'],
            'wifi_ssid' => ['nullable', 'string', 'max:191'],
            'mac_address' => ['nullable', 'string', 'max:17'],
            'gateway' => ['nullable', 'ip'],
            // Baterai
            'battery_present' => ['nullable', 'boolean'],
            'battery_percent' => ['nullable', 'integer', 'between:0,100'],
            'battery_charging' => ['nullable', 'boolean'],
            'battery_health_percent' => ['nullable', 'integer', 'between:0,100'],
            // Disk
            'disks' => ['nullable', 'array', 'max:26'],
            'disks.*.drive' => ['required', 'string', 'max:3'],
            'disks.*.total' => ['nullable', 'integer', 'min:0'],
            'disks.*.free' => ['nullable', 'integer', 'min:0'],
            'physical_disks' => ['nullable', 'array', 'max:16'],
            'physical_disks.*.name' => ['required', 'string', 'max:191'],
            'physical_disks.*.media' => ['nullable', 'string', 'max:20'],
            'physical_disks.*.size' => ['nullable', 'integer', 'min:0'],
            'physical_disks.*.health' => ['nullable', 'string', 'in:healthy,warning,unhealthy,unknown'],
            // Windows Update
            'reboot_pending' => ['nullable', 'boolean'],
            'pending_updates_count' => ['nullable', 'integer', 'between:0,65535'],
            'pending_updates' => ['nullable', 'array', 'max:25'],
            'pending_updates.*' => ['string', 'max:255'],
            // Prosesor
            'cpu_manufacturer' => ['nullable', 'string', 'max:64'],
            'cpu_cores' => ['nullable', 'integer', 'between:1,1024'],
            'cpu_logical' => ['nullable', 'integer', 'between:1,2048'],
            'cpu_max_mhz' => ['nullable', 'integer', 'between:0,20000'],
            'cpu_current_mhz' => ['nullable', 'integer', 'between:0,20000'],
            'cpu_load_percent' => ['nullable', 'integer', 'between:0,100'],
            'cpu_l2_kb' => ['nullable', 'integer', 'min:0'],
            'cpu_l3_kb' => ['nullable', 'integer', 'min:0'],
            // Memori
            'ram_free_bytes' => ['nullable', 'integer', 'min:0'],
            'ram_slots_total' => ['nullable', 'integer', 'between:0,64'],
            'ram_max_bytes' => ['nullable', 'integer', 'min:0'],
            'ram_modules' => ['nullable', 'array', 'max:64'],
            'ram_modules.*.slot' => ['nullable', 'string', 'max:64'],
            'ram_modules.*.capacity' => ['nullable', 'integer', 'min:0'],
            'ram_modules.*.speed_mhz' => ['nullable', 'integer', 'between:0,20000'],
            'ram_modules.*.type' => ['nullable', 'string', 'max:16'],
            'ram_modules.*.form_factor' => ['nullable', 'string', 'max:16'],
            'ram_modules.*.manufacturer' => ['nullable', 'string', 'max:64'],
            'ram_modules.*.part_number' => ['nullable', 'string', 'max:64'],
            // Kepatuhan (hanya-baca): anggota Administrators lokal dan status penyimpanan USB
            'local_admins' => ['nullable', 'array', 'max:50'],
            'local_admins.*.name' => ['required', 'string', 'max:191'],
            'local_admins.*.type' => ['nullable', 'string', 'max:16'],
            'local_admins.*.source' => ['nullable', 'string', 'max:32'],
            'builtin_admin_enabled' => ['nullable', 'boolean'],
            'usb_storage' => ['nullable', 'string', 'in:allowed,blocked,write_blocked,unknown'],
            // Benchmark kecepatan baca/tulis (MB/s), inventaris harian
            'ram_read_mbps' => ['nullable', 'integer', 'between:0,2000000'],
            'ram_write_mbps' => ['nullable', 'integer', 'between:0,2000000'],
            'disk_read_mbps' => ['nullable', 'integer', 'between:0,2000000'],
            'disk_write_mbps' => ['nullable', 'integer', 'between:0,2000000'],
            // Kartu grafis / VGA (agent v10): adaptor virtual (Remote Desktop, dsb.) sudah disaring di agent
            'gpus' => ['nullable', 'array', 'max:8'],
            'gpus.*.name' => ['required', 'string', 'max:191'],
            'gpus.*.kind' => ['nullable', 'string', 'in:integrated,discrete,unknown'],
            'gpus.*.vram_bytes' => ['nullable', 'integer', 'min:0'],
            'gpus.*.driver_version' => ['nullable', 'string', 'max:64'],
            'gpus.*.driver_date' => ['nullable', 'string', 'max:32'],
            'gpus.*.processor' => ['nullable', 'string', 'max:191'],
            'gpus.*.resolution' => ['nullable', 'string', 'max:24'],
            'gpus.*.refresh_hz' => ['nullable', 'integer', 'between:0,1000'],
            'gpus.*.status' => ['nullable', 'string', 'max:32'],
            // Software terpasang (inventaris harian)
            'software' => ['nullable', 'array', 'max:1500'],
            'software.*.name' => ['required', 'string', 'max:191'],
            'software.*.version' => ['nullable', 'string', 'max:64'],
            'software.*.publisher' => ['nullable', 'string', 'max:191'],
        ]);

        // Bukan kolom heartbeat: hanya sinyal untuk pembuatan token program pembantu (dipakai di bawah).
        $helperMissing = (bool) ($data['helper_missing'] ?? false);
        unset($data['helper_missing']);

        // Inventaris (software, update tertunda) ditandai waktu kirimnya; bagian yang tidak dikirim tidak menimpa data lama.
        if (array_key_exists('software', $data) && $data['software'] !== null) {
            $data['software_count'] = count($data['software']);
            $data['software_reported_at'] = now();
        }
        if (array_key_exists('pending_updates_count', $data) && $data['pending_updates_count'] !== null) {
            $data['updates_checked_at'] = now();
        }

        foreach (['ram_read_mbps', 'ram_write_mbps', 'disk_read_mbps', 'disk_write_mbps'] as $bench) {
            if (array_key_exists($bench, $data) && $data[$bench] !== null) {
                $data['benchmark_at'] = now();
                break;
            }
        }

        $asset->forceFill(['agent_last_seen' => now(), 'agent_status' => 'online'])->save();

        // Riwayat perubahan spesifikasi: bandingkan dengan laporan sebelumnya SEBELUM ditimpa. Kegagalan pencatatan
        // tidak boleh menggagalkan laporan agent.
        try {
            app(AssetSpecChangeRecorder::class)->record($asset, $asset->agentHeartbeat()->first(), $data);
        } catch (\Throwable $e) {
            report($e);
        }

        // Satu baris TERKINI per aset (upsert), bukan log yang terus bertambah: dengan ratusan aset melapor
        // tiap beberapa menit, menyimpan tiap laporan sebagai baris baru akan membengkak tanpa batas.
        AssetHeartbeat::updateOrCreate(['asset_id' => $asset->id], $data + ['reported_at' => now()]);

        // Perintah untuk laptop ini: yang kedaluwarsa ditutup, yang masih aktif dikirim (dan ditandai terkirim).
        // Agent hanya menjalankan jenis perintah dari daftar tetap dan melaporkan hasilnya ke commands/{id}/result.
        $asset->agentCommands()->whereIn('status', AssetAgentCommand::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())->update(['status' => 'expired', 'finished_at' => now()]);
        // reorder(): relasi agentCommands() sudah terurut terbaru-dulu; agent harus menerima yang tertua lebih dulu.
        $commands = $asset->agentCommands()->active()->reorder()->orderBy('id')->limit(AssetAgentCommand::MAX_ACTIVE_PER_ASSET)->get();
        $asset->agentCommands()->whereIn('id', $commands->where('status', 'pending')->pluck('id'))
            ->update(['status' => 'sent', 'delivered_at' => now()]);

        // Token khusus program pembantu (SIMA Agent Helper): dibuat bila belum ada, hampir habis, atau laptop memintanya.
        // Plaintext hanya dikirim saat dibuat; agent menulisnya ke berkas yang bisa dibaca pengguna laptop itu.
        $helper = null;
        $helperToken = AssetHelperToken::where('asset_id', $asset->id)->first();
        if (! $helperToken || $helperToken->needsRotation() || $helperMissing) {
            [$helperToken, $plain] = AssetHelperToken::rotateFor($asset);
            $helper = ['token' => $plain, 'expires_at' => $helperToken->expires_at->toIso8601String()];
        }

        return response()->json([
            'status' => 'ok',
            'helper' => $helper,
            // latest_agent_version: agent membandingkan dengan versinya sendiri; 0 = tidak ada rilis, jangan update.
            'latest_agent_version' => $this->manifest()['version'] ?? 0,
            'commands' => $commands->map(fn ($c) => [
                'id' => $c->id,
                'type' => $c->type,
                'params' => (object) ($c->params ?? []),
            ])->values(),
        ]);
    }

    /** Hasil perintah dari agent: POST /api/agent/v1/commands/{id}/result */
    public function commandResult(Request $request, int $id): JsonResponse
    {
        /** @var Asset $asset */
        $asset = $request->attributes->get('agent_asset');

        $command = AssetAgentCommand::find($id);
        abort_unless($command && $command->asset_id === $asset->id, 404);

        $data = $request->validate([
            'status' => ['required', 'in:done,failed'],
            'result' => ['nullable', 'string'],
        ]);

        // Sudah selesai, dibatalkan, atau kedaluwarsa: abaikan (agent tidak perlu mengulang).
        if (! in_array($command->status, AssetAgentCommand::STATUS_ACTIVE, true)) {
            return response()->json(['status' => 'ignored']);
        }

        $command->update([
            'status' => $data['status'],
            'finished_at' => now(),
            'result' => mb_substr((string) ($data['result'] ?? ''), 0, AssetAgentCommand::RESULT_MAX),
        ]);

        return response()->json(['status' => 'ok']);
    }

    public function version(): JsonResponse
    {
        return response()->json(['agent_version' => $this->manifest()['version'] ?? self::CURRENT_AGENT_VERSION]);
    }

    /** Info paket update untuk agent bertoken: GET /api/agent/v1/update?current=N */
    public function update(Request $request): JsonResponse
    {
        $m = $this->manifest();
        $current = (int) $request->query('current', 0);

        if ($m === null || $m['version'] <= $current) {
            return response()->json(['update' => false, 'version' => $m['version'] ?? self::CURRENT_AGENT_VERSION]);
        }

        return response()->json([
            'update' => true,
            'version' => $m['version'],
            'sha256' => $m['sha256'],
            'size' => $m['size'],
            'signature' => $m['signature'],
            'notes' => $m['notes'] ?? '',
        ]);
    }

    /** Unduh paket zip yang sedang dirilis: GET /api/agent/v1/update/package?version=N */
    public function package(Request $request)
    {
        $m = $this->manifest();
        abort_if($m === null || (int) $request->query('version') !== $m['version'], 404);

        $file = $this->updatesDir().'/'.$m['file'];
        abort_unless(is_file($file), 404);

        return response()->download($file, $m['file'], ['Content-Type' => 'application/zip']);
    }
}
