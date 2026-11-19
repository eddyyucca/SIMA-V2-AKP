<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Daftar IP address perangkat jaringan/server beserta kredensialnya (password disimpan terenkripsi). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_addresses', function (Blueprint $t) {
            $t->id();
            $t->string('device_name');
            $t->string('ip_address', 45);
            $t->string('username')->nullable();
            $t->text('password')->nullable();                 // terenkripsi (cast "encrypted")
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->text('notes')->nullable();
            $t->dateTime('password_changed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index('ip_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_addresses');
    }
};
