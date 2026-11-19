<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pencocokan request ITERA dengan data IT Asset oleh tim IT: karyawan tertaut dan barang (aset / accessories / consumable / komponen) per request. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('i_t_requests', function (Blueprint $t) {
            $t->foreignId('employee_id')->nullable()->after('user_id')->constrained('employees')->nullOnDelete();
        });
        Schema::create('request_item_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('request_id')->constrained('i_t_requests')->cascadeOnDelete();
            $t->string('kind', 20);                         // asset | accessory | consumable | component
            $t->unsignedBigInteger('item_id');
            $t->decimal('qty', 12, 2)->default(1);
            $t->string('note', 250)->nullable();
            $t->foreignId('assignment_id')->nullable()->constrained('asset_assignments')->nullOnDelete();   // BAST pinjam aset (Peminjaman Aset)
            $t->timestamp('processed_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['request_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_item_links');
        Schema::table('i_t_requests', fn (Blueprint $t) => $t->dropConstrainedForeignId('employee_id'));
    }
};
