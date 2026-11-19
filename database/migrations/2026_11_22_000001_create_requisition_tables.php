<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Requisition IT (digabung ke IT Asset): permintaan (ITR, BAK, Ticket Sistem, WO, Surat Jalan, TT, Peminjaman Aset)
 * dan langganan push notification browser. Skema sama dengan aplikasi System Requisition yang berjalan (hasil gabungan dari
 * seluruh migrasi aslinya). Hanya MENAMBAH tabel baru, tidak mengubah tabel IT Asset yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('i_t_requests')) {
            Schema::create('i_t_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('sequence_number')->nullable();
                $t->string('type')->nullable();
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('requester_name');
                $t->string('nik')->nullable();
                $t->string('department')->nullable();
                $t->string('position')->nullable();
                $t->string('recipient_name')->nullable();
                $t->string('recipient_phone')->nullable();
                $t->string('title');
                $t->string('category')->nullable();
                $t->dateTime('loan_start_date')->nullable();
                $t->dateTime('loan_end_date')->nullable();
                $t->timestamp('returned_at')->nullable();
                $t->timestamp('pre_due_reminder_sent_at')->nullable();
                $t->timestamp('due_reminder_sent_at')->nullable();
                $t->timestamp('last_overdue_reminder_at')->nullable();
                $t->unsignedInteger('qty')->default(1);
                $t->json('items')->nullable();
                $t->text('description');
                $t->string('status')->default('Pending');
                $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('approved_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamps();
                $t->string('priority')->nullable()->default('Medium');
                $t->text('admin_notes')->nullable();
                $t->text('update_note')->nullable();
                $t->text('analisa')->nullable();
                $t->text('rekomendasi')->nullable();
                $t->string('attachment')->nullable();
                $t->string('document_path')->nullable();
                $t->string('document_name')->nullable();
                $t->timestamp('numbered_at')->nullable();
            });
        }

        if (! Schema::hasTable('push_subscriptions')) {
            Schema::create('push_subscriptions', function (Blueprint $t) {
                $t->id();
                $t->morphs('subscribable', 'push_subscriptions_subscribable_morph_idx');
                $t->string('endpoint', 1024)->charset('ascii')->unique();
                $t->string('public_key')->nullable();
                $t->string('auth_token')->nullable();
                $t->string('content_encoding')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('i_t_requests');
    }
};
