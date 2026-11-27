<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Persetujuan atasan (supervisor HRIS) sebelum request masuk ke Tim IT. Hanya menambah kolom. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('i_t_requests', function (Blueprint $t) {
            $t->unsignedBigInteger('supervisor_employee_id')->nullable()->index();
            $t->timestamp('supervisor_asked_at')->nullable();
            $t->timestamp('supervisor_decided_at')->nullable();
            $t->string('supervisor_decision', 20)->nullable();     // approved | rejected | escalated
            $t->text('supervisor_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('i_t_requests', fn (Blueprint $t) => $t->dropColumn(['supervisor_employee_id', 'supervisor_asked_at', 'supervisor_decided_at', 'supervisor_decision', 'supervisor_note']));
    }
};
