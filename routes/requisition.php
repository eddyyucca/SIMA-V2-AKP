<?php

use App\Http\Controllers\PeminjamanController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\WeeklyReportController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| System Requisition IT (digabung ke IT Asset)
|--------------------------------------------------------------------------
| Semua rute ada di bawah /requisition dan memakai LOGIN IT ASSET (sesi yang sama dengan /admin). Nama rute sengaja
| tidak diubah dari aplikasi System Requisition aslinya supaya tampilan dan notifikasinya tetap bekerja apa adanya.
| Peran SR diturunkan dari peran IT Asset (lihat App\Models\User::getRoleAttribute):
|   Admin -> super_admin, Staff IT -> admin, Manajemen/User -> user.
*/
Route::middleware([Authenticate::class, \App\Http\Middleware\EnforcePortalSession::class, \App\Http\Middleware\ForcePasswordChange::class])->prefix('requisition')->group(function () {
    Route::get('/', [RequestController::class, 'index'])->name('dashboard');
    Route::get('/requests/list-all-form', [RequestController::class, 'listAllForm'])->name('requests.list-all-form');
    Route::get('/requests/pending-approvals', [RequestController::class, 'pendingApprovalsJson'])->name('requests.pending-approvals');
    Route::get('/requests/create', [RequestController::class, 'create'])->name('requests.create');
    Route::get('/requests/history', [RequestController::class, 'publicHistoryJson'])->name('requests.history');
    Route::post('/requests', [RequestController::class, 'store'])->name('requests.store');
    // Request saya (riwayat lengkap + filter) dan persetujuan atasan (hanya akun yang punya anak buah di HRIS)
    Route::get('/saya', [RequestController::class, 'mine'])->name('requests.mine');
    Route::get('/atasan', [RequestController::class, 'supervisorIndex'])->name('requests.supervisor');
    Route::post('/atasan/{id}', [RequestController::class, 'supervisorDecide'])->name('requests.supervisor.decide');
    // Pencarian untuk formulir request: karyawan (isi otomatis NIK / department / jabatan) dan saran nama barang ITR. Semua peran, dibatasi frekuensinya.
    Route::get('/lookup/employees', [\App\Http\Controllers\EmployeeLookupController::class, 'employees'])->middleware('throttle:120,1')->name('lookup.employees');
    Route::get('/lookup/items', [\App\Http\Controllers\EmployeeLookupController::class, 'items'])->middleware('throttle:120,1')->name('lookup.items');
    Route::get('/requests/{id}/pdf', [RequestController::class, 'downloadPdf'])->name('requests.pdf');
    // Bukan admin-only: pemilik request boleh edit request miliknya sendiri yang sudah Rejected (otorisasi diperiksa di controller)
    Route::patch('/requests/{id}', [RequestController::class, 'update'])->name('requests.update');

    Route::middleware('requisition.role:admin,super_admin')->group(function () {
        Route::patch('/requests/{id}/status', [RequestController::class, 'updateStatus'])->name('requests.update-status');
        Route::patch('/requests/{id}/note', [RequestController::class, 'updateNote'])->name('requests.update-note');
        Route::post('/requests/{id}/document', [RequestController::class, 'uploadDocument'])->name('requests.document.upload');
        Route::get('/requests/{id}/document', [RequestController::class, 'viewDocument'])->name('requests.document.view');
        // Wajib sebelum '/requests/{id}' supaya "bulk-destroy" tidak ditangkap sebagai parameter {id}.
        Route::delete('/requests/bulk-destroy', [RequestController::class, 'bulkDestroy'])->name('requests.bulk-destroy');
    });

    // Pemilik boleh hapus request miliknya yang sudah Rejected, semua tipe form (otorisasi lengkap di controller)
    Route::delete('/requests/{id}', [RequestController::class, 'destroy'])->name('requests.destroy');

    // Laporan mingguan Ticket Sistem & WO (PPTX) - tim IT
    Route::middleware('requisition.role:admin,super_admin')->group(function () {
        Route::get('/settings/reports/weekly', [WeeklyReportController::class, 'download'])->name('settings.reports.weekly');
    });

    // List "Peminjaman Aset" - terpisah dari List All Form, khusus tim IT
    Route::middleware('requisition.role:admin,super_admin')->group(function () {
        Route::get('/peminjaman', [PeminjamanController::class, 'index'])->name('peminjaman.index');
        Route::get('/peminjaman/list', [PeminjamanController::class, 'listJson'])->name('peminjaman.list');
        Route::patch('/peminjaman/{id}/returned', [PeminjamanController::class, 'markReturned'])->name('peminjaman.mark-returned');
    });

    // Push notification browser: subscribe/unsubscribe untuk semua peran; isi notifikasi dibedakan di backend.
    Route::post('/push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::post('/push/unsubscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');

    // Keluar: memakai sesi login IT Asset (nama rute 'logout' dipakai tampilan System Requisition).
    Route::post('/logout', function (Request $request) {
        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('filament.admin.auth.login');
    })->name('logout');
});

// Laravel memakai route('login') untuk mengarahkan tamu / sesi habis; login-nya adalah login IT Asset.
Route::get('/login', fn () => redirect()->route('filament.admin.auth.login'))->name('login');
