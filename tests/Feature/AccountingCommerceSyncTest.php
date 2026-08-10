<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Tests\Feature;

use Illuminate\Testing\TestResponse;
use SqlSync\LaravelSqlSync\Models\AccountingCurrency;
use SqlSync\LaravelSqlSync\Models\AccountingPriceOffer;
use SqlSync\LaravelSqlSync\Models\AccountingProductCurrencyBinding;
use SqlSync\LaravelSqlSync\Tests\TestCase;

final class AccountingCommerceSyncTest extends TestCase
{
    public function test_accounting_multi_currency_transport_is_canonical_idempotent_and_fail_closed(): void
    {
        $this->postAccounting('currencies', 'currency-batch-1', [
            [
                'provider_source_id' => '1',
                'name' => 'US Dollar',
                'latin_name' => 'US Dollar',
                'code' => 'USD',
                'iso_code' => 'USD',
                'is_base' => true,
                'rate_to_base' => 999,
            ],
            [
                'provider_source_id' => '2',
                'name' => 'Syrian Pound',
                'latin_name' => 'Syrian Pound',
                'code' => 'SYP',
                'iso_code' => 'SYP',
                'is_base' => false,
                'rate_to_base' => 13000,
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('sqlsync_accounting_currencies', [
            'source_provider' => 'al_ameen',
            'provider_source_id' => '1',
            'is_base' => 1,
            'rate_to_base' => 1,
        ]);
        $this->assertDatabaseHas('sqlsync_accounting_currencies', [
            'source_provider' => 'al_ameen',
            'provider_source_id' => '2',
            'is_base' => 0,
            'rate_to_base' => 13000,
        ]);

        $failedBinding = $this->postAccounting('product-currency-bindings', 'binding-retry-1', [
            [
                'product_source_id' => '6293',
                'currency_source_id' => '999',
            ],
        ]);
        $failedBinding->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(0, AccountingProductCurrencyBinding::query()->count());

        $this->postAccounting('currencies', 'currency-batch-2', [
            [
                'provider_source_id' => '999',
                'name' => 'Test Currency',
                'code' => 'TST',
                'iso_code' => 'TST',
                'is_base' => false,
                'rate_to_base' => null,
            ],
        ])->assertOk();

        $retriedBinding = $this->postAccounting('product-currency-bindings', 'binding-retry-1', [
            [
                'product_source_id' => '6293',
                'currency_source_id' => '999',
            ],
        ]);
        $retriedBinding->assertOk()->assertJsonPath('replay', false);
        $this->assertDatabaseHas('sqlsync_accounting_product_currency_bindings', [
            'source_provider' => 'al_ameen',
            'product_source_id' => '6293',
            'currency_source_id' => '999',
        ]);

        $this->postAccounting('product-currency-bindings', 'binding-batch-2', [
            [
                'product_source_id' => '6293',
                'currency_source_id' => '2',
            ],
        ])->assertOk();

        $offer = $this->postAccounting('price-offers', 'offer-batch-1', [
            [
                'product_source_id' => '6293',
                'price_key' => 'retail',
                'label' => 'Retail',
                'amount' => 215.77,
                'currency_source_id' => '2',
                'unit' => 'piece',
            ],
        ]);
        $offer->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('sqlsync_accounting_price_offers', [
            'source_provider' => 'al_ameen',
            'product_source_id' => '6293',
            'price_key' => 'retail',
            'amount' => 215.77,
            'currency_source_id' => '2',
        ]);

        $replay = $this->postAccounting('price-offers', 'offer-batch-1', [
            [
                'product_source_id' => '6293',
                'price_key' => 'retail',
                'label' => 'Retail',
                'amount' => 215.77,
                'currency_source_id' => '2',
                'unit' => 'piece',
            ],
        ]);
        $replay->assertOk()->assertJsonPath('replay', true);
        $this->assertSame(1, AccountingPriceOffer::query()
            ->where('product_source_id', '6293')
            ->where('price_key', 'retail')
            ->count());

        $this->postAccounting('currencies', 'currency-delete-2', [
            [
                'provider_source_id' => '2',
                'is_deleted' => true,
            ],
        ])->assertOk();

        $this->assertFalse(AccountingCurrency::query()
            ->where('provider_source_id', '2')
            ->exists());
        $this->assertFalse(AccountingProductCurrencyBinding::query()
            ->where('currency_source_id', '2')
            ->exists());
        $this->assertFalse(AccountingPriceOffer::query()
            ->where('currency_source_id', '2')
            ->exists());
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function postAccounting(string $endpoint, string $idempotencyKey, array $records): TestResponse
    {
        return $this->postJson(
            "/sqlsync/agent/sync/accounting/{$endpoint}",
            [
                'version' => 2,
                'provider' => 'al_ameen',
                'batch' => [
                    'index' => 0,
                    'count' => 1,
                    'idempotency_key' => $idempotencyKey,
                ],
                'records' => $records,
            ],
            $this->agentHeaders(),
        );
    }
}
