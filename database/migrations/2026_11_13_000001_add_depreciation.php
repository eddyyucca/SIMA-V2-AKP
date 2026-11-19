<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $t) {
            $t->unsignedTinyInteger('useful_life_years')->nullable();
            $t->decimal('residual_percent', 5, 2)->default(0);
        });
        Schema::table('assets', function (Blueprint $t) {
            $t->unsignedTinyInteger('useful_life_years')->nullable();
            $t->decimal('residual_percent', 5, 2)->nullable();
            $t->date('retired_at')->nullable();
        });
        Schema::table('disposals', function (Blueprint $t) {
            $t->decimal('sale_price', 15, 2)->nullable();
        });

        // umur ekonomis bawaan untuk kategori aset yang sudah ada
        foreach (['Server' => 5, 'Smartphone' => 3, 'Tablet' => 3] as $name => $years) {
            DB::table('categories')->where('type', 'asset')->where('name', $name)->update(['useful_life_years' => $years]);
        }
        DB::table('categories')->where('type', 'asset')->whereNull('useful_life_years')->update(['useful_life_years' => 4]);

        // tanggal pelepasan: disposal = tanggal persetujuan; hilang = tanggal terakhir diubah
        foreach (DB::table('disposals')->where('status', 'approved')->whereNotNull('approved_at')->get() as $d) {
            DB::table('assets')->where('id', $d->asset_id)->where('status', 'disposed')->update(['retired_at' => substr((string) $d->approved_at, 0, 10)]);
        }
        foreach (DB::table('assets')->where('status', 'lost')->whereNull('retired_at')->get() as $a) {
            DB::table('assets')->where('id', $a->id)->update(['retired_at' => substr((string) $a->updated_at, 0, 10)]);
        }
    }

    public function down(): void
    {
        Schema::table('disposals', fn (Blueprint $t) => $t->dropColumn('sale_price'));
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn(['useful_life_years', 'residual_percent', 'retired_at']));
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn(['useful_life_years', 'residual_percent']));
    }
};
