<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rencana kedatangan karyawan onsite: barang disiapkan & dicadangkan sebelum orangnya datang. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('arrival_plans', function (Blueprint $t) {
            $t->id();
            $t->string('plan_no')->unique();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->foreignId('department_id')->nullable()->constrained();
            $t->string('position')->nullable();
            $t->foreignId('location_id')->nullable()->constrained();
            $t->date('arrival_date');
            $t->enum('status', ['planned', 'prepared', 'ready', 'handed_over', 'cancelled'])->default('planned');
            $t->foreignId('pic_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('handed_over_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('arrival_date');
            $t->index('status');
        });
        Schema::create('arrival_plan_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('arrival_plan_id')->constrained()->cascadeOnDelete();
            $t->enum('type', ['asset', 'accessory']);
            $t->foreignId('asset_id')->nullable()->constrained();
            $t->foreignId('accessory_id')->nullable()->constrained();
            $t->unsignedInteger('qty')->default(1);
            $t->enum('status', ['planned', 'prepared', 'ready', 'handed_over', 'cancelled'])->default('planned');
            $t->text('note')->nullable();
            $t->dateTime('ready_at')->nullable();
            $t->foreignId('asset_assignment_id')->nullable()->constrained('asset_assignments')->nullOnDelete();
            $t->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $t->timestamps();
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arrival_plan_items');
        Schema::dropIfExists('arrival_plans');
    }
};
