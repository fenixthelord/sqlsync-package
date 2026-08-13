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
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('source_provider', 50);
            $table->string('provider_source_id', 191);
            $table->string('name');
            $table->string('latin_name')->nullable();
            $table->string('code')->nullable();
            $table->string('iso_code', 3)->nullable();
            $table->boolean('is_base')->default(false);
            $table->decimal('rate_to_base', 30, 12)->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('company_id', 'sqlsync_ac_currency_company_idx');
            $table->index('source_provider', 'sqlsync_ac_currency_provider_idx');
            $table->index('iso_code', 'sqlsync_ac_currency_iso_idx');
            $table->index('is_base', 'sqlsync_ac_currency_base_idx');
            $table->unique(
                ['company_id', 'source_provider', 'provider_source_id'],
                'sqlsync_ac_currency_source_uq',
            );
        });

        Schema::create('sqlsync_accounting_product_currency_bindings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('source_provider', 50);
            $table->string('product_source_id', 191);
            $table->string('currency_source_id', 191);
            $table->json('provider_metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('company_id', 'sqlsync_ac_binding_company_idx');
            $table->index('source_provider', 'sqlsync_ac_binding_provider_idx');
            $table->index('currency_source_id', 'sqlsync_ac_binding_currency_idx');
            $table->unique(
                ['company_id', 'source_provider', 'product_source_id'],
                'sqlsync_ac_binding_product_uq',
            );
        });

        Schema::create('sqlsync_accounting_price_offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('source_provider', 50);
            $table->string('product_source_id', 191);
            $table->string('price_key', 80);
            $table->string('label')->nullable();
            $table->decimal('amount', 30, 12);
            $table->string('currency_source_id', 191);
            $table->string('unit')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('company_id', 'sqlsync_ac_offer_company_idx');
            $table->index('source_provider', 'sqlsync_ac_offer_provider_idx');
            $table->index('product_source_id', 'sqlsync_ac_offer_product_idx');
            $table->index('currency_source_id', 'sqlsync_ac_offer_currency_idx');
            $table->unique(
                ['company_id', 'source_provider', 'product_source_id', 'price_key'],
                'sqlsync_ac_offer_product_key_uq',
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
