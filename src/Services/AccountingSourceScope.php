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
        $scopeKey = $this->scopeKey($companyId);

        return DB::transaction(function () use (
            $scopeKey,
            $companyId,
            $provider,
            $accountingSourceUuid,
        ): bool {
            $scope = $this->lockedScope($scopeKey);

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

            $sameSource = $this->sameSource($scope, $accountingSourceUuid);
            $sameProvider = strtolower((string) $scope->source_provider) === $provider;

            if ($sameSource && $sameProvider) {
                return false;
            }

            $this->clearCanonicalRows($companyId);

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

    /**
     * Observe a source UUID before the provider is known (heartbeat path).
     *
     * If this installation already has a source binding and the SQL database
     * changes, canonical accounting truth is cleared immediately. We preserve
     * the previous provider label temporarily; the first source-aware core or
     * commerce batch will call ensure() and update it if the provider changed.
     * A brand-new installation with no scope is left untouched until a batch
     * supplies both provider and source identity, avoiding an ambiguous bind.
     */
    public function observeSource(
        string $accountingSourceUuid,
        ?int $companyId = null,
    ): bool {
        $accountingSourceUuid = strtolower(trim($accountingSourceUuid));
        $scopeKey = $this->scopeKey($companyId);

        return DB::transaction(function () use (
            $scopeKey,
            $companyId,
            $accountingSourceUuid,
        ): bool {
            $scope = $this->lockedScope($scopeKey);

            if ($scope === null || $this->sameSource($scope, $accountingSourceUuid)) {
                return false;
            }

            $this->clearCanonicalRows($companyId);

            DB::table('sqlsync_accounting_source_scopes')
                ->where('scope_key', $scopeKey)
                ->update([
                    'accounting_source_uuid' => $accountingSourceUuid,
                    'updated_at' => now(),
                ]);

            return true;
        });
    }

    private function lockedScope(string $scopeKey): ?object
    {
        return DB::table('sqlsync_accounting_source_scopes')
            ->where('scope_key', $scopeKey)
            ->lockForUpdate()
            ->first();
    }

    private function sameSource(object $scope, string $accountingSourceUuid): bool
    {
        return hash_equals(
            strtolower((string) $scope->accounting_source_uuid),
            $accountingSourceUuid,
        );
    }

    private function clearCanonicalRows(?int $companyId): void
    {
        // One Laravel company is paired to one active accounting source at a
        // time. Delete dependants before currencies so no stale canonical
        // commerce truth survives a SQL source switch.
        $this->deleteCompanyRows('sqlsync_accounting_price_offers', $companyId);
        $this->deleteCompanyRows('sqlsync_accounting_product_currency_bindings', $companyId);
        $this->deleteCompanyRows('sqlsync_accounting_currencies', $companyId);
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

    private function scopeKey(?int $companyId): string
    {
        return $companyId === null ? 'global' : 'company:' . $companyId;
    }
}
