<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Models;

use Illuminate\Database\Eloquent\Model;

class BridgeSetting extends Model
{
    protected $table = 'sqlsync_bridge_settings';

    protected $fillable = [
        'company_id',
        'enabled',
        'target_model',
        'match_source',
        'match_target',
        'fields',
        'create_defaults',
        'skip_create_if_missing_defaults',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'fields' => 'array',
        'create_defaults' => 'array',
        'skip_create_if_missing_defaults' => 'boolean',
    ];

    /**
     * Returns the single settings row for the given company (or the
     * global row when multi_tenant is off), creating an empty one if
     * it doesn't exist yet so the Filament form always has something
     * to bind to.
     */
    public static function current(?int $companyId = null): self
    {
        return static::firstOrCreate(
            ['company_id' => $companyId],
            [
                'enabled' => false,
                'fields' => [],
                'create_defaults' => [],
                'skip_create_if_missing_defaults' => true,
            ]
        );
    }
}
