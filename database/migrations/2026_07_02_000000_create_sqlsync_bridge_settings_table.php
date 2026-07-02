<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Singleton-style table: exactly one row per company (or one row
        // total when multi_tenant is off). Lets a project configure how
        // SqlSync's synced_records map onto ITS OWN product model, from
        // the Filament UI, without editing any PHP/config files.
        Schema::create('sqlsync_bridge_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->unique();

            $table->boolean('enabled')->default(false);

            // Fully-qualified Eloquent class name of the project's own
            // product model, e.g. App\Models\Product.
            $table->string('target_model')->nullable();

            // Match column: how we find "is this synced item already a
            // row in my table?" — e.g. source 'barcode' -> target 'sku'.
            $table->string('match_source')->nullable();
            $table->string('match_target')->nullable();

            // [{ "target": "price", "source": "extra_data.mtRetail" }, ...]
            $table->json('fields')->nullable();

            // Static defaults used only when creating a brand-new row,
            // e.g. { "publication_status": "draft" }. Any column left
            // without a value here blocks auto-creation for that row
            // (it stays visible only in sqlsync_records for manual review).
            $table->json('create_defaults')->nullable();

            $table->boolean('skip_create_if_missing_defaults')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sqlsync_bridge_settings');
    }
};
