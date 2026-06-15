<?php

namespace SqlSync\LaravelSqlSync\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $table = 'sqlsync_logs';

    public $timestamps = false;

    protected $fillable = [
        'agent_id',
        'company_id',
        'preset',
        'inserted',
        'updated',
        'skipped',
        'status',
        'message',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];
}
