<?php

namespace SqlSync\LaravelSqlSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncedRecord extends Model
{
    protected $table = 'sqlsync_records';

    protected $fillable = [
        'company_id',
        'preset',
        'source_guid',
        'agent_id',
        'name',
        'latin_name',
        'code',
        'barcode',
        'group_name',
        'unit',
        'quantity',
        'is_active',
        'extra_data',   // JSON — holds preset-specific fields (prices, origin, etc.)
        'synced_at',
    ];

    protected $casts = [
        'extra_data' => 'array',
        'is_active'  => 'boolean',
        'synced_at'  => 'datetime',
        'quantity'   => 'float',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(SyncAgent::class, 'agent_id', 'agent_id');
    }
}
