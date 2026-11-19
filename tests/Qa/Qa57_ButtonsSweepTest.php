<?php

namespace Tests\Qa;

use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Livewire\Livewire;
use Tests\QaTestCase;

/** Sapuan otomatis: setiap halaman daftar dibuka, diurutkan, dicari, difilter, dan setiap tombol (header/baris) dibuka tanpa error. */
class Qa57_ButtonsSweepTest extends QaTestCase
{
    public function test_every_list_page_sorts_searches_filters_and_opens_its_buttons(): void
    {
        $this->as($this->admin());
        $problems = [];
        $checked = ['pages' => 0, 'sort' => 0, 'search' => 0, 'filters' => 0, 'rowActions' => 0, 'headerActions' => 0];

        foreach (Filament::getResources() as $resource) {
            $indexPage = $resource::getPages()['index'] ?? null;
            if (! $indexPage) continue;
            $class = $indexPage->getPage();
            $name = class_basename($resource);
            if (! is_subclass_of($class, ListRecords::class)) continue;
            try {
                $t = Livewire::test($class)->assertSuccessful();
                $checked['pages']++;
                $table = $t->instance()->getTable();

                foreach ($table->getColumns() as $col) {
                    if ($col->isSortable()) { $t->sortTable($col->getName())->assertSuccessful(); $t->sortTable($col->getName(), 'desc')->assertSuccessful(); $checked['sort']++; }
                }
                $t->sortTable(null);
                foreach ($table->getColumns() as $col) {
                    if ($col->isSearchable()) { $t->searchTable('a')->assertSuccessful(); $checked['search']++; break; }
                }
                $t->searchTable(null);

                foreach ($table->getFilters() as $f) {
                    $t->resetTableFilters();
                    $fname = $f->getName();
                    if ($f instanceof \Filament\Tables\Filters\SelectFilter) {
                        $opt = array_key_first($f->getOptions());
                        if ($opt !== null) { $t->filterTable($fname, $opt)->assertSuccessful(); $checked['filters']++; }
                    } elseif ($f instanceof \Filament\Tables\Filters\TrashedFilter) {
                        foreach ([true, false] as $v) { $t->filterTable($fname, $v)->assertSuccessful(); }
                        $checked['filters']++;
                    } elseif ($f instanceof \Filament\Tables\Filters\Filter) {
                        $t->filterTable($fname, true)->assertSuccessful(); $checked['filters']++;   // filter sakelar (Stok <= minimum, dll.)
                    }
                    $t->resetTableFilters();
                }

                $record = $t->instance()->getTableRecords()->first();
                foreach ($table->getFlatActions() as $a) {
                    if (! $record || $a->getName() === 'delete' || str_contains($a->getName(), 'delete')) continue;
                    try {
                        $t->mountTableAction($a->getName(), $record);
                        $t->unmountTableAction();
                        $checked['rowActions']++;
                    } catch (\Throwable $e) {
                        if (! str_contains($e->getMessage(), 'is not visible') && ! str_contains($e->getMessage(), 'authorized')) $problems[] = "{$name} tombol baris '{$a->getName()}': " . substr($e->getMessage(), 0, 140);
                    }
                }
                foreach ($t->instance()->getCachedHeaderActions() as $a) {
                    if (! $a->isVisible() || ! method_exists($a, 'getName')) continue;
                    try { $t->mountAction($a->getName()); $t->unmountAction(); $checked['headerActions']++; }
                    catch (\Throwable $e) { $problems[] = "{$name} tombol header '{$a->getName()}': " . substr($e->getMessage(), 0, 140); }
                }
            } catch (\Throwable $e) {
                $problems[] = "{$name}: " . substr($e->getMessage(), 0, 160);
            }
        }
        fwrite(STDERR, "\nSAPUAN: " . json_encode($checked) . "\n");
        $this->assertSame([], $problems, implode("\n", $problems));
        $this->assertGreaterThan(15, $checked['pages']);
    }
}
