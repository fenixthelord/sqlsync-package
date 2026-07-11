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
        'auto_slug_column',
        'source_number_column',
        'fallback_match_fields',
        'fields',
        'create_defaults',
        'skip_create_if_missing_defaults',
        'category_model',
        'category_source',
        'category_use_tree_resolution',
        'category_match_column',
        'category_target_field',
        'category_slug_column',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'fallback_match_fields' => 'array',
        'fields' => 'array',
        'create_defaults' => 'array',
        'skip_create_if_missing_defaults' => 'boolean',
        'category_use_tree_resolution' => 'boolean',
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

        $rawValue = \Illuminate\Support\Arr::get($recordArray, $this->category_source);

        if (blank($rawValue)) {
            return null;
        }

        $value = $rawValue;

        if ($this->category_use_tree_resolution) {
            // category_source holds a raw hierarchical path (e.g. Al-Bayan's
            // TreeNum: '117185'), not a display name. Resolve it to the
            // nearest ancestor category's readable name before running the
            // normal find-or-create flow below — otherwise we'd end up
            // creating bogus categories literally named '117185'.
            $resolved = app(\SqlSync\LaravelSqlSync\Services\TreeCategoryResolver::class)
                ->resolveName((string) $rawValue, $this->company_id);

            if ($resolved === null) {
                // No ancestor node found anywhere in the synced tree for
                // this path — the item was never filed under a category
                // in the accounting software. Leave it uncategorized
                // rather than inventing a category named after a raw
                // numeric code; a human should file it properly at the
                // source and it'll pick up the right category next sync.
                return null;
            }

            $value = $resolved;
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

        try {
            $category = $modelClass::create($attributes);

            return $category->getKey();
        } catch (\Throwable $e) {
            // A failure creating a NEW category (e.g. some other NOT
            // NULL column on the categories table we don't know about,
            // a race with a concurrent sync creating the same name)
            // must never bubble up and threaten the isolation of the
            // record currently being processed. Degrade to "couldn't
            // resolve a category" — the caller falls through to
            // create_defaults' category_id, and the product itself
            // still gets created successfully instead of being lost.
            \Illuminate\Support\Facades\Log::warning('SqlSync: failed to create category node', [
                'value' => $value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Resolves an existing product using the composite fallback match
     * (name + brand, or whatever the admin configured in Filament) —
     * used only when the primary match_source is blank on a record.
     *
     * Every configured pair must have a non-blank value on this record
     * AND match exactly (AND'd WHERE clauses) for a hit; a single blank
     * field means the fallback itself can't be trusted for this record
     * and the caller should skip rather than guess.
     *
     * @return array{query_ok: bool, existing: ?\Illuminate\Database\Eloquent\Model}
     *   query_ok = false means the fallback fields themselves were
     *   incomplete on this record (nothing to search with at all).
     */
    public function resolveFallbackMatch(string $modelClass, array $recordArray): array
    {
        $pairs = $this->fallback_match_fields ?? [];

        if (empty($pairs)) {
            return ['query_ok' => false, 'existing' => null];
        }

        $query = $modelClass::query();

        foreach ($pairs as $pair) {
            $source = $pair['source'] ?? null;
            $target = $pair['target'] ?? null;

            if (blank($source) || blank($target)) {
                return ['query_ok' => false, 'existing' => null];
            }

            $value = \Illuminate\Support\Arr::get($recordArray, $source);

            if (blank($value)) {
                // Any missing piece of the composite key makes the whole
                // fallback untrustworthy for this specific record — two
                // items with blank brand would otherwise collide.
                return ['query_ok' => false, 'existing' => null];
            }

            $query->where($target, $value);
        }

        return ['query_ok' => true, 'existing' => $query->first()];
    }

    /**
     * Human-readable description of the fallback key for a given record,
     * used in Bridge Activity log messages so support/customers can see
     * exactly what values were compared.
     */
    /**
     * Generates a guaranteed-unique, guaranteed-non-null slug for a
     * synced record. NEVER derives from a raw source field directly —
     * confirmed in production that fields commonly used for this
     * (e.g. Al-Bayan's 'code') are frequently blank and never
     * guaranteed unique across the catalogue, causing every creation
     * to fail with either a NOT NULL or a UNIQUE constraint violation.
     *
     * name + a short hash of source_guid: source_guid is the sync
     * system's own primary key (Al-Bayan's internal item number),
     * always present, always unique. The name makes the slug at least
     * somewhat readable/SEO-friendly; the hash suffix is what actually
     * guarantees uniqueness even if two products happen to share a
     * name.
     */
    public function generateSafeSlug(string $name, string $sourceGuid): string
    {
        $base = \Illuminate\Support\Str::slug($name);

        if ($base === '') {
            // Str::slug() can legitimately return '' for names that are
            // ENTIRELY non-Latin (e.g. pure Arabic with no transliteration
            // rules configured) — fall back to a generic prefix rather
            // than shipping an empty base.
            $base = 'item';
        }

        return $base.'-'.substr(md5($sourceGuid), 0, 8);
    }

    public function describeFallbackKey(array $recordArray): string
    {
        $pairs = $this->fallback_match_fields ?? [];
        $parts = [];

        foreach ($pairs as $pair) {
            $source = $pair['source'] ?? null;
            if (! $source) {
                continue;
            }
            $value = \Illuminate\Support\Arr::get($recordArray, $source);
            $parts[] = ($pair['target'] ?? $source).'='.($value ?? '—');
        }

        return implode(', ', $parts);
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
