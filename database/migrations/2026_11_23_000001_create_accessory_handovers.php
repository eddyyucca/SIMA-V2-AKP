<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serah terima accessories antara IT dan karyawan (mis. mouse): satu dokumen BAST bisa memuat beberapa barang, bisa terkait
 * request ITR dari System Requisition, dan scan yang sudah ditandatangani diarsipkan permanen. Hanya menambah tabel baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessory_handovers', function (Blueprint $t) {
            $t->id();
            $t->string('bast_number')->unique();
            $t->string('type', 10)->default('checkout');                 // checkout | return
            $t->foreignId('employee_id')->constrained('employees');
            $t->foreignId('request_id')->nullable()->constrained('i_t_requests')->nullOnDelete();
            $t->dateTime('handed_at');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['employee_id', 'handed_at']);
        });

        Schema::create('accessory_handover_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('accessory_handover_id')->constrained('accessory_handovers')->cascadeOnDelete();
            $t->foreignId('accessory_id')->constrained('accessories');
            $t->unsignedInteger('qty');
            $t->string('condition', 20)->nullable();                     // kondisi saat dikembalikan
            $t->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('accessory_handover_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('accessory_handover_id')->constrained('accessory_handovers')->cascadeOnDelete();
            $t->string('path');
            $t->string('original_name')->nullable();
            $t->string('note')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessory_handover_files');
        Schema::dropIfExists('accessory_handover_items');
        Schema::dropIfExists('accessory_handovers');
    }
};
