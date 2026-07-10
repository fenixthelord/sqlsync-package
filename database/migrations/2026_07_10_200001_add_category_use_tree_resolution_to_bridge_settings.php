<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sqlsync_bridge_settings', function (Blueprint $table) {
            // When true, category_source is treated as a hierarchical
            // tree-path value (e.g. Al-Bayan's TreeNum) and resolved to
            // a readable category NAME via TreeCategoryResolver before
            // the normal find-or-create-by-name flow runs. When false
            // (default — matches every existing installation's current
            // behaviour unchanged), category_source's raw value is used
            // directly as before.
            $table->boolean('category_use_tree_resolution')->default(false)->after('category_source');
        });
    }

    public function down(): void
    {
        Schema::table('sqlsync_bridge_settings', function (Blueprint $table) {
            $table->dropColumn('category_use_tree_resolution');
        });
    }
};
