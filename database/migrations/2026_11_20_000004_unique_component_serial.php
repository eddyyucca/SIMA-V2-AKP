<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Serial komponen unik seperti serial aset (NULL boleh berulang). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('components')->where('serial_number', '')->update(['serial_number' => null]);
        Schema::table('components', fn (Blueprint $t) => $t->unique('serial_number'));
    }

    public function down(): void
    {
        Schema::table('components', fn (Blueprint $t) => $t->dropUnique(['serial_number']));
    }
};
