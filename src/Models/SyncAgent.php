<?php

namespace SqlSync\LaravelSqlSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncAgent extends Model
{
    protected $table = 'sqlsync_agents';

    protected $fillable = [
        'agent_id',
        'company_id',
        'label',
        'last_heartbeat',
        'last_sync_at',
        'total_synced',
        'meta',
    ];

    protected $casts = [
        'last_heartbeat' => 'datetime',
        'last_sync_at'   => 'datetime',
        'meta'           => 'array',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(SyncedRecord::class, 'agent_id', 'agent_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SyncLog::class, 'agent_id', 'agent_id');
    }

    public function isOnline(): bool
    {
        return $this->last_heartbeat && $this->last_heartbeat->diffInMinutes(now()) <= 5;
    }
}
