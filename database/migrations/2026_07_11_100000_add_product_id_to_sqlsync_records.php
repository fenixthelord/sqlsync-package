<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sqlsync_records', function (Blueprint $table) {
            // Once a SyncedRecord is successfully bridged to a Product
            // (created or matched), we remember that link here — so
            // future syncs of the SAME source item (same source_guid)
            // always update the SAME product directly, regardless of
            // what changes about it (barcode, name, anything used for
            // matching). Without this, a barcode change or name change
            // on the source side looks like "a completely new item" on
            // every subsequent sync, producing a duplicate product
            // instead of an update.
            $table->unsignedBigInteger('product_id')->nullable()->after('extra_data');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('sqlsync_records', function (Blueprint $table) {
            $table->dropColumn('product_id');
        });
    }
};
