<?php

namespace SqlSync\LaravelSqlSync\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AccountingCurrency extends Model
{
    protected $table = 'sqlsync_accounting_currencies';

    protected $fillable = [
        'company_id',
        'source_provider',
        'provider_source_id',
        'name',
        'latin_name',
        'code',
        'iso_code',
        'is_base',
        'rate_to_base',
        'provider_metadata',
        'synced_at',
    ];

    protected $casts = [
        'is_base' => 'boolean',
        'rate_to_base' => 'decimal:12',
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
