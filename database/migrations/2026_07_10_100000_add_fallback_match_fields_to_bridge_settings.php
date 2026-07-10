<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sqlsync_bridge_settings', function (Blueprint $table) {
            // Composite fallback match — used ONLY when match_source is
            // blank on a record (e.g. items with no barcode). Holds an
            // array of {source, target} pairs; ALL must match (AND) for
            // the fallback to resolve an existing product.
            //
            // Example for a pharmacy where items commonly lack barcodes:
            //   [
            //     {"source": "name",              "target": "name"},
            //     {"source": "extra_data.origin",  "target": "brand"}
            //   ]
            $table->json('fallback_match_fields')->nullable()->after('match_target');
        });
    }

    public function down(): void
    {
        Schema::table('sqlsync_bridge_settings', function (Blueprint $table) {
            $table->dropColumn('fallback_match_fields');
        });
    }
};
