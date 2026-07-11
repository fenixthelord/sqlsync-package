<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sqlsync_bridge_settings', function (Blueprint $table) {
            // Default false = the original, safe behavior: once a
            // product's category is set on creation, it's NEVER
            // re-resolved on later syncs — protects any manual category
            // reassignment an admin makes on the website from being
            // silently overwritten by the next sync cycle.
            //
            // When true, every update ALSO re-runs category resolution
            // against the current synced data — useful for stores that
            // want the website to always mirror the accounting
            // software's current classification, and don't do manual
            // category overrides on the website side.
            $table->boolean('category_reresolve_on_update')->default(false)->after('category_use_tree_resolution');
        });
    }

    public function down(): void
    {
        Schema::table('sqlsync_bridge_settings', function (Blueprint $table) {
            $table->dropColumn('category_reresolve_on_update');
        });
    }
};
