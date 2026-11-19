<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Master Merek & Model aset. Kolom teks assets.brand / assets.model tetap ada (disinkronkan dari master). */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('asset_models', function (Blueprint $t) {
            $t->id();
            $t->foreignId('brand_id')->constrained('brands');
            $t->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $t->string('name');
            $t->text('default_specs')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['brand_id', 'name']);
        });
        Schema::table('assets', function (Blueprint $t) {
            $t->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $t->foreignId('asset_model_id')->nullable()->constrained('asset_models')->nullOnDelete();
        });

        // isi master dari data aset yang sudah ada (tanpa membedakan huruf besar/kecil)
        $brandIds = [];
        foreach (DB::table('assets')->whereNotNull('brand')->where('brand', '!=', '')->orderBy('id')->get(['id', 'brand', 'model', 'category_id']) as $a) {
            $key = mb_strtolower(trim($a->brand));
            $brandIds[$key] ??= DB::table('brands')->insertGetId(['name' => trim($a->brand), 'created_at' => now(), 'updated_at' => now()]);
            $modelId = null;
            if (filled($a->model)) {
                $existing = DB::table('asset_models')->where('brand_id', $brandIds[$key])->whereRaw('lower(name) = ?', [mb_strtolower(trim($a->model))])->value('id');
                $modelId = $existing ?? DB::table('asset_models')->insertGetId([
                    'brand_id' => $brandIds[$key], 'category_id' => $a->category_id, 'name' => trim($a->model), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('assets')->where('id', $a->id)->update([
                'brand_id' => $brandIds[$key], 'asset_model_id' => $modelId,
                'brand' => DB::table('brands')->where('id', $brandIds[$key])->value('name'),
                'model' => $modelId ? DB::table('asset_models')->where('id', $modelId)->value('name') : $a->model,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('asset_model_id');
            $t->dropConstrainedForeignId('brand_id');
        });
        Schema::dropIfExists('asset_models');
        Schema::dropIfExists('brands');
    }
};
