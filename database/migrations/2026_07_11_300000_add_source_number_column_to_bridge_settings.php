<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sqlsync_bridge_settings', function (Blueprint $table) {
            // Name of a column on the customer's OWN Products table that
            // permanently stores the accounting software's internal item
            // number (source_guid, e.g. 'bayan-21372') directly on the
            // product row itself.
            //
            // This is deliberately separate from — and more durable than
            // — sqlsync_records.product_id (the "sticky link" added
            // earlier). That link lives on SqlSync's own bookkeeping
            // table and is lost if sqlsync_records is ever wiped (e.g.
            // a Danger Zone reset that doesn't also wipe Products).
            // Storing the identity directly ON the product survives
            // that entirely — even a full SqlSync-side data wipe can
            // re-establish every link on the very next sync, with zero
            // reliance on barcode/name matching, because the product
            // itself still carries its permanent accounting-software
            // identity.
            $table->string('source_number_column')->nullable()->after('match_target');
        });
    }

    public function down(): void
    {
        Schema::table('sqlsync_bridge_settings', function (Blueprint $table) {
            $table->dropColumn('source_number_column');
        });
    }
};
