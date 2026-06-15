<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Agents ─────────────────────────────────────────────────────────
        Schema::create('sqlsync_agents', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id')->unique();           // Windows machine fingerprint
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('label')->nullable();            // Human-readable name
            $table->timestamp('last_heartbeat')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->unsignedBigInteger('total_synced')->default(0);
            $table->json('meta')->nullable();               // Extra info from agent
            $table->timestamps();
        });

        // ── Synced Records ──────────────────────────────────────────────────
        Schema::create('sqlsync_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('preset', 50)->index();         // al_ameen / al_bayan / ...
            $table->string('source_guid', 100)->index();   // GUID from source DB
            $table->string('agent_id')->index();
            $table->string('name');
            $table->string('latin_name')->nullable();
            $table->string('code', 100)->nullable()->index();
            $table->string('barcode', 100)->nullable()->index();
            $table->string('group_name')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->json('extra_data')->nullable();        // Preset-specific fields
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // One record per source_guid per company
            $table->unique(['source_guid', 'company_id']);
        });

        // ── Sync Logs ───────────────────────────────────────────────────────
        Schema::create('sqlsync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id')->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('preset', 50);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->string('status', 20)->default('success'); // success / error
            $table->text('message')->nullable();
            $table->timestamp('synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sqlsync_logs');
        Schema::dropIfExists('sqlsync_records');
        Schema::dropIfExists('sqlsync_agents');
    }
};
