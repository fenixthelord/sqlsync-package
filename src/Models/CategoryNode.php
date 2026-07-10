<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single node in the source accounting software's classification tree
 * (Al-Bayan's MatCard rows where Kind = 1). Synced separately from
 * regular products via the 'categories' dataset so TreeCategoryResolver
 * can walk a product's raw tree-path value (its group_guid field, e.g.
 * '117185') up to the nearest matching node ('117' -> 'اطفال مختلف').
 */
class CategoryNode extends Model
{
    protected $table = 'sqlsync_category_nodes';

    protected $fillable = [
        'agent_id',
        'company_id',
        'source_num',
        'tree_num',
        'name',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];
}
