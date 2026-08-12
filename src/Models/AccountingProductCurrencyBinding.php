<?php

namespace SqlSync\LaravelSqlSync\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AccountingProductCurrencyBinding extends Model
{
    protected $table = 'sqlsync_accounting_product_currency_bindings';

    protected $fillable = [
        'company_id',
        'source_provider',
        'product_source_id',
        'currency_source_id',
        'provider_metadata',
        'synced_at',
    ];

    protected $casts = [
        'provider_metadata' => 'array',
        'synced_at' => 'datetime',
    ];

    public function scopeForCompany(Builder $query, ?int $companyId): Builder
    {
        return $companyId === null
            ? $query->whereNull('company_id')
            : $query->where('company_id', $companyId);
    }
}
