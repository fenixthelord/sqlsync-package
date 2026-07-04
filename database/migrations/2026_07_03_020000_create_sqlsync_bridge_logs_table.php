<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sqlsync_bridge_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreignId('synced_record_id')->nullable()->constrained('sqlsync_records')->nullOnDelete();

            $table->string('record_name')->nullable();
            $table->string('match_value')->nullable();

            // created | updated | skipped
            $table->string('action', 20)->index();

            // Only relevant when action = skipped: missing_match | missing_defaults | db_error
            $table->string('reason', 40)->nullable();
            $table->text('detail')->nullable();

            $table->string('target_model')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sqlsync_bridge_logs');
    }
};
