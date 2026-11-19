<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nomor dokumen pembelian aset kini "No. PO / PR" (isian lama ikut terbawa). */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('assets', 'invoice_no') && ! Schema::hasColumn('assets', 'po_pr_no')) {
            Schema::table('assets', fn (Blueprint $t) => $t->renameColumn('invoice_no', 'po_pr_no'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('assets', 'po_pr_no') && ! Schema::hasColumn('assets', 'invoice_no')) {
            Schema::table('assets', fn (Blueprint $t) => $t->renameColumn('po_pr_no', 'invoice_no'));
        }
    }
};
