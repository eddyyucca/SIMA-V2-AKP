<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Aset sewa (rental): kepemilikan, data kontrak, dan status "Dikembalikan ke Vendor". */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('assets', 'ownership')) {
            Schema::table('assets', function (Blueprint $t) {
                $t->string('ownership', 10)->default('owned');
                $t->string('rental_contract_no')->nullable();
                $t->date('rental_start')->nullable();
                $t->date('rental_end')->nullable();
                $t->decimal('rental_monthly_cost', 15, 2)->default(0);
                $t->decimal('rental_deposit', 15, 2)->default(0);
                $t->date('returned_at')->nullable();
                $t->text('return_notes')->nullable();
                $t->index('ownership');
            });
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE assets MODIFY status ENUM('available','deployed','repair','borrowed','lost','disposed','returned') NOT NULL DEFAULT 'available'");
        }
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->dropIndex(['ownership']);
            $t->dropColumn(['ownership', 'rental_contract_no', 'rental_start', 'rental_end', 'rental_monthly_cost', 'rental_deposit', 'returned_at', 'return_notes']);
        });
    }
};
