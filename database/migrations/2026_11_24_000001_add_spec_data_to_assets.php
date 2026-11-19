<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spesifikasi laptop terstruktur (name, cpu, ram, hdd, vga, motherboard, os). Kolom "specs" (teks) tidak diubah. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', fn (Blueprint $t) => $t->json('spec_data')->nullable()->after('specs'));
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn('spec_data'));
    }
};
