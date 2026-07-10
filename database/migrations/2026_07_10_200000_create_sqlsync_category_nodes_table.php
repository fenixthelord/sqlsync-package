<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sqlsync_category_nodes', function (Blueprint $table) {
            $table->id();

            $table->string('agent_id', 64)->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();

            // Al-Bayan's MatCard.Num for this node — same numbering
            // sequence as products (MatCard mixes category-tree nodes
            // and sellable items in one table, distinguished by Kind).
            $table->integer('source_num');

            // The node's own hierarchical path segment, e.g. '117' for a
            // root category or '117185' for something nested under it.
            // A PRODUCT's group_guid field (also TreeNum) is resolved to
            // a category NAME by walking this table's tree_num values —
            // see TreeCategoryResolver.
            $table->string('tree_num', 32)->index();

            $table->string('name');

            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['company_id', 'source_num']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sqlsync_category_nodes');
    }
};
