<?php

namespace SqlSync\LaravelSqlSync\Models;

use Illuminate\Database\Eloquent\Model;

class License extends Model
{
    protected $table = 'sqlsync_licenses';

    protected $fillable = [
        'license_key',
        'machine_id',
        'agent_id',
        'company_id',
        'expires_at',
        'activated_at',
        'last_verified_at',
        'status',
        'meta',
    ];

    protected $casts = [
        'expires_at'       => 'datetime',
        'activated_at'     => 'datetime',
        'last_verified_at' => 'datetime',
        'meta'             => 'array',
    ];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isBoundToMachine(?string $machineId): bool
    {
        return $this->machine_id !== null
            && $machineId !== null
            && hash_equals($this->machine_id, $machineId);
    }
}
