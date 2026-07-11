<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sqlsync_bridge_settings', function (Blueprint $table) {
            // JSON array of column names that should receive an
            // automatically-generated, guaranteed-unique value on
            // creation whenever no real data covers them. Generalizes
            // the same reasoning as auto_slug_column (never derive a
            // constraint-critical value from a raw, possibly-blank
            // source field) to ANY required column the wizard's smart
            // suggestion couldn't confidently map — e.g. a required
            // 'reference_code' column with no obvious source equivalent.
            $table->json('auto_generate_columns')->nullable()->after('source_number_column');
        });
    }

    public function down(): void
    {
        Schema::table('sqlsync_bridge_settings', function (Blueprint $table) {
            $table->dropColumn('auto_generate_columns');
        });
    }
};
