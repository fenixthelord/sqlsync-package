<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sqlsync_bridge_settings', function (Blueprint $table) {
            // When set, this column always gets a guaranteed-unique,
            // guaranteed-non-null slug computed from the record's name
            // + a short hash of its source_guid — never a raw source
            // field (which, as seen in production, is frequently blank
            // and never guaranteed unique — e.g. Al-Bayan's 'code' field
            // is empty for many pharmacy items and repeats for others).
            $table->string('auto_slug_column')->nullable()->after('match_target');
        });
    }

    public function down(): void
    {
        Schema::table('sqlsync_bridge_settings', function (Blueprint $table) {
            $table->dropColumn('auto_slug_column');
        });
    }
};
