<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIMA Asset Agent (custom SIMA): status "terakhir lapor" per aset, dibaca langsung tanpa join
 * ke asset_heartbeats. Lihat docs/rencana-agent-monitoring-aset.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->timestamp('agent_last_seen')->nullable()->after('last_audit_date');
            $table->enum('agent_status', ['online', 'offline', 'never_reported'])
                ->default('never_reported')->after('agent_last_seen');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['agent_last_seen', 'agent_status']);
        });
    }
};
