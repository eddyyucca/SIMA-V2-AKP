<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tiket perbaikan yang dibuat dari request BAK / Ticket Sistem (menyambungkan ITERA dengan modul Perbaikan). Hanya menambah kolom. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('request_item_links', function (Blueprint $t) {
            $t->foreignId('repair_id')->nullable()->constrained('repairs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('request_item_links', fn (Blueprint $t) => $t->dropConstrainedForeignId('repair_id'));
    }
};
