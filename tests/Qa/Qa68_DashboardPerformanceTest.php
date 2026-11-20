<?php

namespace Tests\Qa;

use App\Models\Employee;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\QaTestCase;

/** Dashboard lambat (2.534 query di produksi): pengaturan dibaca sekali per permintaan, dan karyawan resign dihitung massal. */
class Qa68_DashboardPerformanceTest extends QaTestCase
{
    public function test_resigned_holding_matches_the_per_employee_check(): void
    {
        $fast = Employee::resignedHolding()->pluck('id')->sort()->values()->all();
        $slow = Employee::where('status', 'resigned')->get()->filter->holdsItems()->pluck('id')->sort()->values()->all();
        $this->assertSame($slow, $fast);

        // satu karyawan resign memegang aset -> ikut terdeteksi; setelah aset dikembalikan -> tidak
        $e = Employee::where('status', 'active')->whereHas('assets')->firstOrFail();
        $e->update(['status' => 'resigned']);
        $this->assertContains($e->id, Employee::resignedHolding()->pluck('id')->all());
    }

    public function test_resigned_holding_uses_a_constant_number_of_queries(): void
    {
        DB::flushQueryLog(); DB::enableQueryLog();
        Employee::resignedHolding();
        $this->assertLessThanOrEqual(5, count(DB::getQueryLog()));
    }

    public function test_settings_are_read_once_per_request_and_stay_fresh_after_changes(): void
    {
        Setting::put('asset_tag_prefix', 'ABC-IT');
        DB::flushQueryLog(); DB::enableQueryLog();
        for ($i = 0; $i < 200; $i++) Setting::get('asset_tag_prefix');
        $this->assertLessThanOrEqual(2, count(DB::getQueryLog()), 'bukan satu kueri per pemanggilan');
        $this->assertSame('ABC-IT', Setting::get('asset_tag_prefix'));

        Setting::put('asset_tag_prefix', 'XYZ-IT');
        $this->assertSame('XYZ-IT', Setting::get('asset_tag_prefix'), 'put() membatalkan salinan');
        Setting::where('key', 'asset_tag_prefix')->first()->update(['value' => 'LMN-IT']);
        $this->assertSame('LMN-IT', Setting::get('asset_tag_prefix'), 'perubahan lewat model juga membatalkan salinan');
    }
}
