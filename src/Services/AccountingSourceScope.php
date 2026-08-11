<?php

namespace SqlSync\LaravelSqlSync\Services;

use Illuminate\Support\Facades\DB;

class AccountingSourceScope
{
    public function ensure(
        string $provider,
        string $accountingSourceUuid,
        ?int $companyId = null,
    ): bool {
        $provider = strtolower(trim($provider));
        $accountingSourceUuid = strtolower(trim($accountingSourceUuid));
        $scopeKey = $companyId === null ? 'global' : 'company:' . $companyId;

        return DB::transaction(function () use (
            $scopeKey,
            $companyId,
            $provider,
            $accountingSourceUuid,
        ): bool {
            $scope = DB::table('sqlsync_accounting_source_scopes')
                ->where('scope_key', $scopeKey)
                ->lockForUpdate()
                ->first();

            // Upgrade compatibility: existing installations may already have
            // canonical accounting rows from the currently paired source but
            // no source-scope row yet. Bind that existing data in place on the
            // first source-aware request; only a later source/provider change
            // is destructive.
            if ($scope === null) {
                DB::table('sqlsync_accounting_source_scopes')->insert([
                    'scope_key' => $scopeKey,
                    'company_id' => $companyId,
                    'source_provider' => $provider,
                    'accounting_source_uuid' => $accountingSourceUuid,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return false;
            }

            $sameSource = hash_equals(
                strtolower((string) $scope->accounting_source_uuid),
                $accountingSourceUuid,
            );
            $sameProvider = strtolower((string) $scope->source_provider) === $provider;

            if ($sameSource && $sameProvider) {
                return false;
            }

            // One Laravel company is paired to one active accounting source at
            // a time. When that source changes, old canonical commerce truth
            // must not survive alongside the new database. Delete dependants
            // first, then currencies, all within the same transaction that
            // advances the binding.
            $this->deleteCompanyRows(
                'sqlsync_accounting_price_offers',
                $companyId,
            );
            $this->deleteCompanyRows(
                'sqlsync_accounting_product_currency_bindings',
                $companyId,
            );
            $this->deleteCompanyRows(
                'sqlsync_accounting_currencies',
                $companyId,
            );

            DB::table('sqlsync_accounting_source_scopes')
                ->where('scope_key', $scopeKey)
                ->update([
                    'company_id' => $companyId,
                    'source_provider' => $provider,
                    'accounting_source_uuid' => $accountingSourceUuid,
                    'updated_at' => now(),
                ]);

            return true;
        });
    }

    private function deleteCompanyRows(string $table, ?int $companyId): void
    {
        $query = DB::table($table);

        if ($companyId === null) {
            $query->whereNull('company_id');
        } else {
            $query->where('company_id', $companyId);
        }

        $query->delete();
    }
}
