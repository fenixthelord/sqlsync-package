<?php

namespace SqlSync\LaravelSqlSync\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AccountingPriceOffer extends Model
{
    protected $table = 'sqlsync_accounting_price_offers';

    protected $fillable = [
        'company_id',
        'source_provider',
        'product_source_id',
        'price_key',
        'label',
        'amount',
        'currency_source_id',
        'unit',
        'provider_metadata',
        'synced_at',
    ];

    protected $casts = [
        'amount' => 'decimal:12',
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
