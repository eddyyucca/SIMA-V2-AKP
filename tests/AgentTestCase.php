<?php

namespace Tests;

use App\Models\Asset;
use App\Models\Category;

/** Dasar tes API agent (dipindah dari SIMA): aset dibuat langsung, tanpa factory Snipe-IT. */
abstract class AgentTestCase extends TestCase
{
    use UsesDemoData;

    private static int $seq = 0;

    protected function makeAsset(array $attrs = []): Asset
    {
        $cat = Category::firstOrCreate(['name' => 'Laptop'], ['type' => 'asset', 'code_prefix' => 'NB']);
        $n = ++self::$seq . '-' . substr(md5(uniqid('', true)), 0, 6);

        return Asset::create($attrs + ['asset_tag' => "AKP-IT-NB-T{$n}", 'category_id' => $cat->id, 'serial_number' => "SN{$n}", 'status' => 'deployed']);
    }
}
