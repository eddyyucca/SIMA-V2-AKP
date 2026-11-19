<?php

use App\Support\AssetTagMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $t) {
                $t->id();
                $t->string('key')->unique();
                $t->text('value')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('assets', 'legacy_tag')) {
            Schema::table('assets', function (Blueprint $t) {
                $t->string('legacy_tag')->nullable()->index();
            });
        }
        \Illuminate\Support\Facades\DB::table('settings')->insertOrIgnore(['key' => 'asset_tag_prefix', 'value' => strtoupper((string) config('asset_tag.prefix')), 'created_at' => now(), 'updated_at' => now()]);
        AssetTagMigrator::renumber();
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn('legacy_tag'));
        Schema::dropIfExists('settings');
    }
};
