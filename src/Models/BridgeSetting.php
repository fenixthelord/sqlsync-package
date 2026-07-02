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
        'category_model',
        'category_source',
        'category_match_column',
        'category_target_field',
        'category_slug_column',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'fields' => 'array',
        'create_defaults' => 'array',
        'skip_create_if_missing_defaults' => 'boolean',
    ];

    /**
     * Resolves (finding or creating) the category referenced by a synced
     * record's data, returning the category id to store on the product —
     * or null if auto-category resolution isn't configured/applicable.
     */
    public function resolveCategoryId(array $recordArray): ?int
    {
        if (! $this->category_model
            || ! class_exists($this->category_model)
            || blank($this->category_source)
            || blank($this->category_match_column)) {
            return null;
        }

        $value = \Illuminate\Support\Arr::get($recordArray, $this->category_source);

        if (blank($value)) {
            return null;
        }

        $modelClass = $this->category_model;

        /** @var \Illuminate\Database\Eloquent\Model|null $category */
        $category = $modelClass::where($this->category_match_column, $value)->first();

        if ($category) {
            return $category->getKey();
        }

        $attributes = [$this->category_match_column => $value];

        if ($this->category_slug_column) {
            $attributes[$this->category_slug_column] = \Illuminate\Support\Str::slug($value).'-'.substr(md5($value), 0, 6);
        }

        $category = $modelClass::create($attributes);

        return $category->getKey();
    }

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
