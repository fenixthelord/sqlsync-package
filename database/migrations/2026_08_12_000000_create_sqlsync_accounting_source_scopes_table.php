<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sqlsync_accounting_source_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key', 80)->unique();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('source_provider', 50)->index();
            $table->uuid('accounting_source_uuid');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sqlsync_accounting_source_scopes');
    }
};
