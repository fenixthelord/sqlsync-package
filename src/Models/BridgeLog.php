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
}
