<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sqlsync_bridge_logs', function (Blueprint $table) {
            $table->index('created_at', 'sqlsync_bridge_logs_created_at_idx');
        });

        Schema::table('sqlsync_logs', function (Blueprint $table) {
            $table->index('synced_at', 'sqlsync_logs_synced_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sqlsync_bridge_logs', function (Blueprint $table) {
            $table->dropIndex('sqlsync_bridge_logs_created_at_idx');
        });

        Schema::table('sqlsync_logs', function (Blueprint $table) {
            $table->dropIndex('sqlsync_logs_synced_at_idx');
        });
    }
};
