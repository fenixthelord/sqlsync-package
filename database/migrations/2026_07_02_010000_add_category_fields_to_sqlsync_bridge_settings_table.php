<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sqlsync_bridge_settings', function (Blueprint $table) {
            // Fully-qualified Eloquent class for the project's Category
            // model, e.g. App\Models\Category. Leave null to disable
            // auto-category resolution entirely.
            $table->string('category_model')->nullable()->after('create_defaults');

            // Which field on the synced record identifies the category,
            // e.g. 'group_name' (supports extra_data.xxx dot notation).
            $table->string('category_source')->nullable()->after('category_model');

            // Which column on the category model we match/create by,
            // e.g. 'name'.
            $table->string('category_match_column')->nullable()->after('category_source');

            // Which column on the PRODUCT model receives the resolved
            // category's id, e.g. 'category_id'.
            $table->string('category_target_field')->nullable()->after('category_match_column');

            // Optional: if the category model has a unique slug column,
            // name it here and we'll auto-generate one from the match
            // value when creating a brand-new category.
            $table->string('category_slug_column')->nullable()->after('category_target_field');
        });
    }

    public function down(): void
    {
        Schema::table('sqlsync_bridge_settings', function (Blueprint $table) {
            $table->dropColumn([
                'category_model',
                'category_source',
                'category_match_column',
                'category_target_field',
                'category_slug_column',
            ]);
        });
    }
};
