<?php

use App\Http\Controllers\Api\EmployeeAssetsIntegrationController;
use Illuminate\Support\Facades\Route;

// Integrasi antar aplikasi AKPSatu (hanya baca, token layanan SIMA_API_TOKEN). Kontrak sama dengan SIMA lama:
// HRIS menampilkan aset yang masih dipegang karyawan (offboarding) lewat GET /api/integrations/employees/{nik}/assets.
Route::middleware(['integration.token', 'throttle:120,1'])->prefix('integrations')->group(function () {
    Route::get('employees/{employeeNo}/assets', [EmployeeAssetsIntegrationController::class, 'show'])
        ->where('employeeNo', '[A-Za-z0-9._-]+');
});
