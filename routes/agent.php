<?php

use App\Http\Controllers\Api\AgentHelperController;
use App\Http\Controllers\Api\AssetAgentController;
use App\Http\Middleware\AssetAgentToken;
use App\Http\Middleware\AssetHelperTokenAuth;
use Illuminate\Support\Facades\Route;

// SIMA Asset Agent (skrip PowerShell di laptop/PC): lapor status online tiap beberapa menit. Hanya baca token per-aset,
// tidak ada data sensitif. Lihat docs/rencana-agent-monitoring-aset.md.
Route::middleware(['throttle:agent-ip', AssetAgentToken::class, 'throttle:agent'])->prefix('api/agent/v1')->group(function () {
    Route::post('heartbeat', [AssetAgentController::class, 'heartbeat']);

    // Hasil perintah dari IT (daftar tetap, lihat App\Models\AssetAgentCommand).
    Route::post('commands/{id}/result', [AssetAgentController::class, 'commandResult'])->whereNumber('id');

    // Pembaruan otomatis agent (paket ditandatangani offline; agent memverifikasi sendiri).
    Route::get('update', [AssetAgentController::class, 'update']);
    Route::get('update/package', [AssetAgentController::class, 'package']);
});

// SIMA Agent Helper (program di sesi pengguna): pengumuman dan chat. Token khusus berumur pendek, cakupan sempit.
Route::middleware(['throttle:agent-ip', AssetHelperTokenAuth::class, 'throttle:agent-helper'])->prefix('api/helper/v1')->group(function () {
    Route::get('poll', [AgentHelperController::class, 'poll']);
    Route::post('announcements/{id}/shown', [AgentHelperController::class, 'shown'])->whereNumber('id');
    Route::post('announcements/{id}/ack', [AgentHelperController::class, 'ack'])->whereNumber('id');
    Route::post('chat/messages', [AgentHelperController::class, 'chatSend']);
});

// Publik (tanpa token): agen cek apakah ada versi skrip baru.
Route::get('api/agent/v1/version', [AssetAgentController::class, 'version'])->middleware('throttle:agent-ip');
