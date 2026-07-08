<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sqlsync_logs', function (Blueprint $table) {
            // Which query.Key within the preset produced these rows.
            // v1 payloads (from pre-Batch-B Agents) leave this null.
            $table->string('dataset', 50)->nullable()->after('preset');

            // Batch metadata — helpful when a single sync window ships
            // in multiple HTTP requests and one of them fails; you can
            // see which slice was missing.
            $table->unsignedSmallInteger('batch_index')->nullable()->after('dataset');
            $table->unsignedSmallInteger('batch_count')->nullable()->after('batch_index');

            // Stable per-batch key. Same window retried after a network
            // hiccup carries the same key, so the receipt lookup can
            // detect replays and return cached counters.
            $table->string('idempotency_key', 64)->nullable()->after('batch_count');

            // Max SinceColumn value observed in this batch's records —
            // useful for "why isn't row X syncing" investigations.
            $table->timestamp('high_watermark')->nullable()->after('idempotency_key');

            // Fast lookup for the replay check in SyncService::process.
            $table->index(['agent_id', 'idempotency_key'], 'sqlsync_logs_replay_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sqlsync_logs', function (Blueprint $table) {
            $table->dropIndex('sqlsync_logs_replay_idx');
            $table->dropColumn([
                'dataset',
                'batch_index',
                'batch_count',
                'idempotency_key',
                'high_watermark',
            ]);
        });
    }
};
