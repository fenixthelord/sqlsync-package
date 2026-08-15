<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Models;

use Illuminate\Database\Eloquent\Model;

class BridgeLog extends Model
{
    protected $table = 'sqlsync_bridge_logs';

    protected $fillable = [
        'company_id',
        'synced_record_id',
        'record_name',
        'match_value',
        'action',
        'reason',
        'detail',
        'target_model',
        'target_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (BridgeLog $log): bool {
            $isSuccessfulAction = in_array($log->action, ['created', 'updated'], true);

            if ($isSuccessfulAction && ! config('sqlsync.bridge.log_successful_actions', false)) {
                return false;
            }

            return true;
        });
    }
}
