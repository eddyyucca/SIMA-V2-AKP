<?php

namespace App\Http\Controllers;

use App\Models\Accessory;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pencarian untuk formulir request ITERA (hanya pengguna yang sudah login):
 *  - karyawan aktif: ketik nama / NIK, pilih, lalu NIK, department, dan jabatan terisi dari database;
 *  - barang (Accessories, Consumable, Komponen) beserta stok tersedia, sebagai saran nama barang pada ITR.
 */
class EmployeeLookupController extends Controller
{
    private const MAX_RESULTS = 8;

    /** Karakter khusus LIKE (% _ \) di-escape supaya ketikan pengguna tidak menjadi pola. */
    private function like(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    }

    public function employees(Request $request): JsonResponse
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 60));
        if (mb_strlen($q) < 2) return response()->json(['data' => []]);

        $like = $this->like($q);
        $rows = Employee::query()->with('department')->where('status', 'active')
            ->where(fn ($w) => $w->where('name', 'like', "%{$like}%")->orWhere('nik', 'like', "{$like}%"))
            ->orderByRaw('CASE WHEN lower(name) LIKE lower(?) THEN 0 ELSE 1 END', ["{$like}%"])->orderBy('name')
            ->limit(self::MAX_RESULTS)->get();

        return response()->json(['data' => $rows->map(fn (Employee $e) => [
            'id' => $e->id,
            'name' => $e->name,
            'nik' => $e->nik ?: null,
            'position' => $e->position ?: null,
            'department' => $e->department?->name,
            'section' => $e->section ?: null,
        ])->values()]);
    }

    public function items(Request $request): JsonResponse
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 60));
        if (mb_strlen($q) < 2) return response()->json(['data' => []]);

        $like = $this->like($q);
        $out = collect();
        foreach (Accessory::where(fn ($w) => $w->where('name', 'like', "%{$like}%")->orWhere('brand_model', 'like', "%{$like}%"))->limit(self::MAX_RESULTS)->get() as $i) {
            $out->push(['ref' => 'accessory:' . $i->id, 'kind' => 'Accessories', 'name' => $i->name, 'detail' => $i->brand_model ?: null, 'stock' => (int) $i->qty_available, 'unit' => null]);
        }
        foreach (Consumable::where('name', 'like', "%{$like}%")->limit(self::MAX_RESULTS)->get() as $i) {
            $out->push(['ref' => 'consumable:' . $i->id, 'kind' => 'Consumable', 'name' => $i->name, 'detail' => $i->compatible_with ?: null, 'stock' => (float) $i->qty, 'unit' => $i->unit]);
        }
        foreach (Component::where(fn ($w) => $w->where('name', 'like', "%{$like}%")->orWhere('specs', 'like', "%{$like}%"))->limit(self::MAX_RESULTS)->get() as $i) {
            $out->push(['ref' => 'component:' . $i->id, 'kind' => 'Komponen', 'name' => $i->name, 'detail' => $i->specs ?: null, 'stock' => (int) $i->qty_available, 'unit' => null]);
        }
        $lq = mb_strtolower($q);
        $sorted = $out->sortBy(fn ($r) => [str_starts_with(mb_strtolower($r['name']), $lq) ? 0 : 1, mb_strtolower($r['name'])])->take(self::MAX_RESULTS)->values();

        return response()->json(['data' => $sorted]);
    }
}
