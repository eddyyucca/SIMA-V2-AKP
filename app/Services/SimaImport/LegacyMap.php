<?php

namespace App\Services\SimaImport;

use Illuminate\Support\Facades\DB;

/** id lama -> id baru, dengan cache di memori agar impor ribuan baris tidak menanyakan DB terus-menerus. */
class LegacyMap
{
    private array $cache = [];

    public function get(string $entity, $legacyId): ?int
    {
        if ($legacyId === null || $legacyId === '') return null;
        $k = "$entity:$legacyId";
        if (! array_key_exists($k, $this->cache)) {
            $this->cache[$k] = DB::table('legacy_map')->where('entity', $entity)->where('legacy_id', $legacyId)->value('new_id');
        }
        return $this->cache[$k] ? (int) $this->cache[$k] : null;
    }

    public function put(string $entity, $legacyId, int $newId): void
    {
        DB::table('legacy_map')->updateOrInsert(['entity' => $entity, 'legacy_id' => $legacyId], ['new_id' => $newId, 'updated_at' => now(), 'created_at' => now()]);
        $this->cache["$entity:$legacyId"] = $newId;
    }

    public function count(string $entity): int { return DB::table('legacy_map')->where('entity', $entity)->count(); }
}
