<?php

namespace App\Http\Controllers\Api;

use App\Filament\Resources\Assets\AssetResource;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

/**
 * Aset yang sedang dipegang seorang karyawan (dikunci NIK, termasuk alias), untuk aplikasi lain (HRIS offboarding).
 * Bentuk respons SAMA dengan SIMA lama; hanya data ringkas, tanpa harga atau nomor seri.
 */
class EmployeeAssetsIntegrationController extends Controller
{
    public function show(string $employeeNo): JsonResponse
    {
        $employee = Employee::withTrashed()->where('nik', $employeeNo)->first();

        $assets = $employee
            ? $employee->assets()->whereIn('status', ['deployed', 'borrowed', 'repair'])->with(['category', 'modelRef', 'assignments'])->get()
                ->map(fn ($a) => [
                    'asset_tag' => $a->asset_tag,
                    'name' => trim("{$a->brand} {$a->model}") ?: $a->asset_tag,
                    'model' => $a->model,
                    'category' => $a->category?->name,
                    'assigned_at' => $a->assignments->whereIn('type', ['checkout', 'transfer'])->first()?->assigned_at?->toDateString(),
                    'url' => AssetResource::getUrl('view', ['record' => $a->id], panel: 'admin'),
                ])->values()
            : collect();

        return response()->json([
            'employee_no' => $employeeNo,
            'found' => $employee !== null,
            'count' => $assets->count(),
            'assets' => $assets,
        ]);
    }
}
