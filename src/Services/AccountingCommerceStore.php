<?php

namespace SqlSync\LaravelSqlSync\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SqlSync\LaravelSqlSync\Models\AccountingCurrency;
use SqlSync\LaravelSqlSync\Models\AccountingPriceOffer;
use SqlSync\LaravelSqlSync\Models\AccountingProductCurrencyBinding;
use Throwable;

class AccountingCommerceStore
{
    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array{received:int,created:int,updated:int,rejected:int,errors:array<int,array<string,mixed>>}
     */
    public function syncCurrencies(string $provider, array $records, ?int $companyId = null): array
    {
        $provider = $this->normalizeProvider($provider);
        $normalized = [];
        $tombstones = [];
        $errors = [];

        foreach ($records as $index => $record) {
            try {
                if ($this->isDeleted($record)) {
                    $tombstones[] = $this->requiredString($record, 'provider_source_id');

                    continue;
                }

                $normalized[] = $this->normalizeCurrencyRecord($record, $index);
            } catch (InvalidArgumentException $exception) {
                $errors[] = $this->error($index, 'invalid_currency', $exception->getMessage());
            }
        }

        $baseRecords = array_values(array_filter(
            $normalized,
            static fn (array $record): bool => $record['is_base'] === true,
        ));

        if (count($baseRecords) > 1) {
            return [
                'received' => count($records),
                'created' => 0,
                'updated' => 0,
                'rejected' => count($records),
                'errors' => [[
                    'reason' => 'ambiguous_base_currency',
                    'message' => 'A provider currency batch may contain at most one base currency.',
                ]],
            ];
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use (
            $provider,
            $companyId,
            $normalized,
            $tombstones,
            $baseRecords,
            &$created,
            &$updated,
        ): void {
            if ($baseRecords !== []) {
                $this->forCompany(AccountingCurrency::query(), $companyId)
                    ->where('source_provider', $provider)
                    ->where('provider_source_id', '!=', $baseRecords[0]['provider_source_id'])
                    ->where('is_base', true)
                    ->update([
                        'is_base' => false,
                        'rate_to_base' => null,
                        'updated_at' => now(),
                    ]);
            }

            foreach ($normalized as $record) {
                $currency = AccountingCurrency::query()->firstOrNew([
                    'company_id' => $companyId,
                    'source_provider' => $provider,
                    'provider_source_id' => $record['provider_source_id'],
                ]);
                $exists = $currency->exists;

                $currency->fill($record + [
                    'company_id' => $companyId,
                    'source_provider' => $provider,
                    'synced_at' => now(),
                ])->save();

                $exists ? $updated++ : $created++;
            }

            foreach (array_unique($tombstones) as $currencySourceId) {
                $this->forCompany(AccountingProductCurrencyBinding::query(), $companyId)
                    ->where('source_provider', $provider)
                    ->where('currency_source_id', $currencySourceId)
                    ->delete();

                $this->forCompany(AccountingPriceOffer::query(), $companyId)
                    ->where('source_provider', $provider)
                    ->where('currency_source_id', $currencySourceId)
                    ->delete();

                $this->forCompany(AccountingCurrency::query(), $companyId)
                    ->where('source_provider', $provider)
                    ->where('provider_source_id', $currencySourceId)
                    ->delete();

                // Tombstones are accepted idempotent changes even when the
                // row is already absent, matching the Cliprz Store contract.
                $updated++;
            }
        });

        return [
            'received' => count($records),
            'created' => $created,
            'updated' => $updated,
            'rejected' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array{received:int,created:int,updated:int,rejected:int,errors:array<int,array<string,mixed>>}
     */
    public function syncProductCurrencyBindings(string $provider, array $records, ?int $companyId = null): array
    {
        $provider = $this->normalizeProvider($provider);
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($records as $index => $record) {
            try {
                $productSourceId = $this->requiredString($record, 'product_source_id');

                if ($this->isDeleted($record)) {
                    $this->forCompany(AccountingProductCurrencyBinding::query(), $companyId)
                        ->where('source_provider', $provider)
                        ->where('product_source_id', $productSourceId)
                        ->delete();
                    $updated++;

                    continue;
                }

                $currencySourceId = $this->requiredString($record, 'currency_source_id');

                if (! $this->currencyExists($provider, $currencySourceId, $companyId)) {
                    throw new InvalidArgumentException(
                        "Unknown accounting currency '{$currencySourceId}' for provider '{$provider}'.",
                    );
                }

                $binding = AccountingProductCurrencyBinding::query()->firstOrNew([
                    'company_id' => $companyId,
                    'source_provider' => $provider,
                    'product_source_id' => $productSourceId,
                ]);
                $exists = $binding->exists;

                $binding->fill([
                    'company_id' => $companyId,
                    'source_provider' => $provider,
                    'currency_source_id' => $currencySourceId,
                    'provider_metadata' => $this->providerMetadata($record),
                    'synced_at' => now(),
                ])->save();

                $exists ? $updated++ : $created++;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $this->error(
                    $index,
                    'invalid_product_currency_binding',
                    $exception->getMessage(),
                );
            } catch (Throwable $exception) {
                $errors[] = $this->error($index, 'persistence_error', $exception->getMessage());
            }
        }

        return $this->result(count($records), $created, $updated, $errors);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array{received:int,created:int,updated:int,rejected:int,errors:array<int,array<string,mixed>>}
     */
    public function syncPriceOffers(string $provider, array $records, ?int $companyId = null): array
    {
        $provider = $this->normalizeProvider($provider);
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($records as $index => $record) {
            try {
                $productSourceId = $this->requiredString($record, 'product_source_id');
                $priceKey = strtolower($this->requiredString($record, 'price_key'));

                if ($this->isDeleted($record)) {
                    $this->forCompany(AccountingPriceOffer::query(), $companyId)
                        ->where('source_provider', $provider)
                        ->where('product_source_id', $productSourceId)
                        ->where('price_key', $priceKey)
                        ->delete();
                    $updated++;

                    continue;
                }

                $currencySourceId = $this->requiredString($record, 'currency_source_id');
                $amount = $this->nonNegativeDecimal($record['amount'] ?? null, 'amount');

                if (! $this->currencyExists($provider, $currencySourceId, $companyId)) {
                    throw new InvalidArgumentException(
                        "Unknown accounting currency '{$currencySourceId}' for provider '{$provider}'.",
                    );
                }

                $offer = AccountingPriceOffer::query()->firstOrNew([
                    'company_id' => $companyId,
                    'source_provider' => $provider,
                    'product_source_id' => $productSourceId,
                    'price_key' => $priceKey,
                ]);
                $exists = $offer->exists;

                $offer->fill([
                    'company_id' => $companyId,
                    'source_provider' => $provider,
                    'label' => $this->optionalString($record, 'label'),
                    'amount' => $amount,
                    'currency_source_id' => $currencySourceId,
                    'unit' => $this->optionalString($record, 'unit'),
                    'provider_metadata' => $this->providerMetadata($record),
                    'synced_at' => now(),
                ])->save();

                $exists ? $updated++ : $created++;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $this->error($index, 'invalid_price_offer', $exception->getMessage());
            } catch (Throwable $exception) {
                $errors[] = $this->error($index, 'persistence_error', $exception->getMessage());
            }
        }

        return $this->result(count($records), $created, $updated, $errors);
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private function normalizeCurrencyRecord(array $record, int $index): array
    {
        if (! array_key_exists('is_base', $record)) {
            throw new InvalidArgumentException("Currency record {$index} is missing is_base.");
        }

        $isBase = filter_var(
            $record['is_base'],
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );

        if ($isBase === null) {
            throw new InvalidArgumentException("Currency record {$index} has an invalid is_base value.");
        }

        return [
            'provider_source_id' => $this->requiredString($record, 'provider_source_id'),
            'name' => $this->requiredString($record, 'name'),
            'latin_name' => $this->optionalString($record, 'latin_name'),
            'code' => $this->optionalString($record, 'code'),
            'iso_code' => $this->normalizeIso($record['iso_code'] ?? null),
            'is_base' => $isBase,
            'rate_to_base' => $isBase
                ? '1'
                : $this->positiveDecimalOrNull($record['rate_to_base'] ?? null),
            'provider_metadata' => $this->providerMetadata($record),
        ];
    }

    /** @param array<string, mixed> $record */
    private function isDeleted(array $record): bool
    {
        if (! array_key_exists('is_deleted', $record)) {
            return false;
        }

        $deleted = filter_var(
            $record['is_deleted'],
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );

        if ($deleted === null) {
            throw new InvalidArgumentException("Field 'is_deleted' must be boolean.");
        }

        return $deleted;
    }

    private function currencyExists(string $provider, string $currencySourceId, ?int $companyId): bool
    {
        return $this->forCompany(AccountingCurrency::query(), $companyId)
            ->where('source_provider', $provider)
            ->where('provider_source_id', $currencySourceId)
            ->exists();
    }

    private function forCompany(Builder $query, ?int $companyId): Builder
    {
        return $companyId === null
            ? $query->whereNull('company_id')
            : $query->where('company_id', $companyId);
    }

    /** @param array<string, mixed> $record */
    private function requiredString(array $record, string $field): string
    {
        $value = $this->optionalString($record, $field);

        if ($value === null) {
            throw new InvalidArgumentException("Missing required field '{$field}'.");
        }

        return $value;
    }

    /** @param array<string, mixed> $record */
    private function optionalString(array $record, string $field): ?string
    {
        if (! array_key_exists($field, $record) || $record[$field] === null) {
            return null;
        }

        if (! is_scalar($record[$field])) {
            return null;
        }

        $value = trim((string) $record[$field]);

        return $value !== '' ? $value : null;
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        $provider = str_replace(['-', ' '], '_', $provider);

        if ($provider === '' || strlen($provider) > 40) {
            throw new InvalidArgumentException('Accounting provider is invalid.');
        }

        return $provider;
    }

    private function normalizeIso(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : null;
    }

    private function positiveDecimalOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decimal = $this->decimal($value, 'rate_to_base');

        return (float) $decimal > 0 ? $decimal : null;
    }

    private function nonNegativeDecimal(mixed $value, string $field): string
    {
        $decimal = $this->decimal($value, $field);

        if ((float) $decimal < 0) {
            throw new InvalidArgumentException("Field '{$field}' must not be negative.");
        }

        return $decimal;
    }

    private function decimal(mixed $value, string $field): string
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new InvalidArgumentException("Field '{$field}' must be numeric.");
        }

        $decimal = trim((string) $value);

        if ($decimal === '' || ! is_numeric($decimal) || ! is_finite((float) $decimal)) {
            throw new InvalidArgumentException("Field '{$field}' must be a finite number.");
        }

        return $decimal;
    }

    /** @param array<string, mixed> $record @return array<string, mixed>|null */
    private function providerMetadata(array $record): ?array
    {
        $metadata = $record['provider_metadata'] ?? null;

        return is_array($metadata) ? $metadata : null;
    }

    /** @return array{index:int,reason:string,message:string} */
    private function error(int $index, string $reason, string $message): array
    {
        return [
            'index' => $index,
            'reason' => $reason,
            'message' => $message,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     * @return array{received:int,created:int,updated:int,rejected:int,errors:array<int,array<string,mixed>>}
     */
    private function result(int $received, int $created, int $updated, array $errors): array
    {
        return [
            'received' => $received,
            'created' => $created,
            'updated' => $updated,
            'rejected' => count($errors),
            'errors' => $errors,
        ];
    }
}
