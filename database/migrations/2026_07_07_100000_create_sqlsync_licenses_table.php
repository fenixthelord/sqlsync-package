<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sqlsync_licenses', function (Blueprint $table) {
            $table->id();

            // Human-readable key (what customer types into the Agent UI)
            // Format: XXXX-XXXX-XXXX-XXXX (16 chars + 3 dashes, letters+digits)
            $table->string('license_key', 32)->unique();

            // Machine binding — filled when the license is first activated.
            // Nullable = key exists but not yet activated on any machine.
            $table->string('machine_id', 64)->nullable()->index();

            // Which agent instance activated this key. Same as X-Agent-ID header.
            $table->string('agent_id', 64)->nullable()->index();

            // Multi-tenant support
            $table->unsignedBigInteger('company_id')->nullable()->index();

            $table->timestamp('expires_at');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();

            // active | suspended | revoked
            $table->string('status', 16)->default('active')->index();

            // Free-form metadata: customer name, plan tier, notes, etc.
            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sqlsync_licenses');
    }
};
