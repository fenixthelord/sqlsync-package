<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sqlsync_field_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('preset', 50)->index();
            $table->string('source_field', 100);
            $table->string('target_label', 100);
            $table->string('target_role', 50)->nullable();
            $table->boolean('is_price')->default(false);
            $table->boolean('is_unit')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['preset', 'source_field', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sqlsync_field_mappings');
    }
};
