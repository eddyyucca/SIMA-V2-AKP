<?php

namespace App\Http\Controllers;

use App\Services\LabelService;
use Illuminate\Http\Request;

class LabelController extends Controller
{
    /** GET /labels/{kind}?ids=1,2,3&tape=24&length=100&copies=1  -> PDF siap cetak (pita Brother TZe). Tanpa tape/length: bawaan per jenis. */
    public function print(Request $request, string $kind, LabelService $svc)
    {
        abort_unless($request->user()->can('view inventory'), 403);
        $ids = collect(explode(',', (string) $request->query('ids')))->map(fn ($i) => (int) $i)->filter()->unique()->take(300)->all();
        abort_if(empty($ids), 422, 'Pilih minimal satu barang.');
        $items = $svc->items($kind, $ids);
        abort_if($items->isEmpty(), 404);
        $def = LabelService::defaults($kind);
        $tape = (int) $request->query('tape', $def['tape']);
        $length = (int) $request->query('length', $def['length']);
        return $svc->pdf($kind, $items, $tape, $length, (int) $request->query('copies', 1), (string) $request->query('orient', 'landscape'))->stream("label-{$kind}-{$tape}mm.pdf");
    }
}
