<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_contracts', fn (Blueprint $t) => $t->string('original_name')->nullable()->after('path'));
    }

    public function down(): void
    {
        Schema::table('rental_contracts', fn (Blueprint $t) => $t->dropColumn('original_name'));
    }
};
