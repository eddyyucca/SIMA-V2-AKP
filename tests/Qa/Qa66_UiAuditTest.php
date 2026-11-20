<?php

namespace Tests\Qa;

use Illuminate\Support\Facades\Artisan;
use Tests\QaTestCase;

/** Audit UI mendalam (pencarian dengan nilai nyata, urut, setiap opsi filter, tampilan kolom, halaman buat/lihat/ubah) pada data demo lengkap. */
class Qa66_UiAuditTest extends QaTestCase
{
    public function test_every_list_page_search_sort_and_filter_works_on_demo_data(): void
    {
        $this->as($this->admin());
        $code = Artisan::call('sima:audit-ui', ['--sample' => 8]);
        $out = Artisan::output();
        fwrite(STDERR, "\n" . implode("\n", array_slice(explode("\n", trim($out)), -40)) . "\n");
        $this->assertSame(0, $code, $out);
    }
}
