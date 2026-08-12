<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sqlsync_accounting_currencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('source_provider', 50)->index();
            $table->string('provider_source_id', 191);
            $table->string('name');
            $table->string('latin_name')->nullable();
            $table->string('code')->nullable();
            $table->string('iso_code', 3)->nullable()->index();
            $table->boolean('is_base')->default(false)->index();
            $table->decimal('rate_to_base', 30, 12)->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'source_provider', 'provider_source_id'],
                'sqlsync_accounting_currencies_source_unique',
            );
        });

        Schema::create('sqlsync_accounting_product_currency_bindings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('source_provider', 50)->index();
            $table->string('product_source_id', 191);
            $table->string('currency_source_id', 191)->index();
            $table->json('provider_metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'source_provider', 'product_source_id'],
                'sqlsync_accounting_product_currency_bindings_product_unique',
            );
        });

        Schema::create('sqlsync_accounting_price_offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('source_provider', 50)->index();
            $table->string('product_source_id', 191)->index();
            $table->string('price_key', 80);
            $table->string('label')->nullable();
            $table->decimal('amount', 30, 12);
            $table->string('currency_source_id', 191)->index();
            $table->string('unit')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'source_provider', 'product_source_id', 'price_key'],
                'sqlsync_accounting_price_offers_offer_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sqlsync_accounting_price_offers');
        Schema::dropIfExists('sqlsync_accounting_product_currency_bindings');
        Schema::dropIfExists('sqlsync_accounting_currencies');
    }
};
