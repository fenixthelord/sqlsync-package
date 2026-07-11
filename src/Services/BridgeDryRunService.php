<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use SqlSync\LaravelSqlSync\Models\BridgeSetting;
use SqlSync\LaravelSqlSync\Models\SyncedRecord;

/**
 * Runs the exact same bridging logic as SyncedRecordBridgeObserver
 * against a small sample of already-synced records, inside a database
 * transaction that is ALWAYS rolled back — nothing this class does is
 * ever persisted, including any category rows resolveCategoryId()
 * would otherwise create.
 *
 * Why this exists: the same class of error (a NOT NULL column with no
 * default, a UNIQUE constraint collision) was discovered three separate
 * times in production, one column at a time, only by running a full
 * 17,000-row sync and reading exception text out of Bridge Activity
 * afterward. This answers "what will actually happen" upfront, against
 * real data and real database constraints, without the cost or risk of
 * a full run.
 *
 * Deliberately duplicates (rather than shares) the core per-record
 * logic from SyncedRecordBridgeObserver — keeping the live, production-
 * critical Observer untouched and simple to reason about was judged
 * more valuable than DRY here, especially since dry-run mode differs in
 * real ways (no BridgeLog writes, no sticky-link persistence, wrapped
 * in a rollback).
 */
class BridgeDryRunService
{
    /**
     * @return array<int, array{
     *   record_name: string,
     *   action: string,
     *   detail: ?string,
     *   match_value: ?string,
     * }>
     */
    public function run(int $sampleSize = 20): array
    {
        $setting = BridgeSetting::current();
        $results = [];

        if (! $setting->enabled) {
            return [[
                'record_name' => '—',
                'action' => 'blocked',
                'detail' => 'الربط التلقائي (enabled) مو مفعّل بالإعدادات — فعّله أول شي.',
                'match_value' => null,
            ]];
        }

        $modelClass = $setting->target_model;
        if (! $modelClass || ! class_exists($modelClass)) {
            return [[
                'record_name' => '—',
                'action' => 'blocked',
                'detail' => "target_model ('{$setting->target_model}') فاضي أو غير موجود.",
                'match_value' => null,
            ]];
        }

        DB::beginTransaction();

        try {
            $records = SyncedRecord::latest('synced_at')->limit($sampleSize)->get();

            if ($records->isEmpty()) {
                return [[
                    'record_name' => '—',
                    'action' => 'blocked',
                    'detail' => 'لا يوجد بيانات متزامنة بعد — شغّل الوكيل أولاً.',
                    'match_value' => null,
                ]];
            }

            foreach ($records as $record) {
                $results[] = $this->simulateOne($record, $setting, $modelClass);
            }
        } finally {
            // ALWAYS roll back — this is a dry run. Nothing here, including
            // any category rows resolveCategoryId() creates along the way,
            // is ever allowed to persist.
            DB::rollBack();
        }

        return $results;
    }

    /**
     * @return array{record_name: string, action: string, detail: ?string, match_value: ?string}
     */
    private function simulateOne(SyncedRecord $record, BridgeSetting $setting, string $modelClass): array
    {
        $recordArray = $record->toArray();
        $matchValue = Arr::get($recordArray, (string) $setting->match_source);
        $usingFallback = false;
        $existing = null;

        if ($setting->source_number_column) {
            $existing = $modelClass::where($setting->source_number_column, $record->source_guid)->first();
        }

        if (! $existing && $record->product_id) {
            $existing = $modelClass::find($record->product_id);
        }

        if (! $existing) {
            if (blank($matchValue) || blank($setting->match_target)) {
                $fallback = $setting->resolveFallbackMatch($modelClass, $recordArray);

                if (! $fallback['query_ok']) {
                    return [
                        'record_name' => $record->name,
                        'action' => 'skipped',
                        'detail' => empty($setting->fallback_match_fields)
                            ? "الحقل '{$setting->match_source}' فاضي، ولا يوجد مطابقة احتياطية معرّفة."
                            : "الحقل '{$setting->match_source}' فاضي، والمطابقة الاحتياطية ({$setting->describeFallbackKey($recordArray)}) غير مكتملة.",
                        'match_value' => null,
                    ];
                }

                $usingFallback = true;
                $existing = $fallback['existing'];
            } else {
                $existing = $modelClass::where($setting->match_target, $matchValue)->first();
            }
        }

        $matchValueForLog = $usingFallback ? $setting->describeFallbackKey($recordArray) : $matchValue;

        $data = [];
        foreach (($setting->fields ?? []) as $targetColumn => $sourceField) {
            $value = $this->resolveFieldValue($recordArray, $sourceField);
            if ($value !== null) {
                $data[$targetColumn] = $value;
            }
        }

        if ($existing) {
            if (! blank($matchValue)) {
                $data[$setting->match_target] = $matchValue;
            }

            try {
                // Real UPDATE attempt against real constraints — inside
                // the transaction this whole run() call rolls back, so
                // no actual change survives, but MySQL still evaluates
                // every constraint exactly as it would in production.
                $existing->update($data);

                return [
                    'record_name' => $record->name,
                    'action' => 'would_update',
                    'detail' => "رح يتحدّث المنتج #{$existing->getKey()}",
                    'match_value' => $matchValueForLog,
                ];
            } catch (\Throwable $e) {
                return [
                    'record_name' => $record->name,
                    'action' => 'error',
                    'detail' => $e->getMessage(),
                    'match_value' => $matchValueForLog,
                ];
            }
        }

        $categoryId = $setting->resolveCategoryId($recordArray);
        if ($categoryId !== null && $setting->category_target_field) {
            $data[$setting->category_target_field] = $categoryId;
        }

        if ($setting->auto_slug_column) {
            $data[$setting->auto_slug_column] = $setting->generateSafeSlug(
                (string) $record->name,
                (string) $record->source_guid,
            );
        }

        $defaults = $setting->create_defaults ?? [];
        $missingRequired = collect($defaults)
            ->reject(fn ($value, $column) => $column === $setting->category_target_field && isset($data[$column]))
            ->contains(fn ($value) => blank($value));

        if ($missingRequired && $setting->skip_create_if_missing_defaults) {
            return [
                'record_name' => $record->name,
                'action' => 'skipped',
                'detail' => 'قيمة افتراضية إجبارية ناقصة (مثل category_id) ولا يوجد مصدر تلقائي لها.',
                'match_value' => $matchValueForLog,
            ];
        }

        if (! blank($matchValue)) {
            $data[$setting->match_target] = $matchValue;
        } elseif ($usingFallback) {
            $data[$setting->match_target] = 'SS-'.$record->source_guid;
        }

        if ($setting->source_number_column) {
            $data[$setting->source_number_column] = $record->source_guid;
        }

        foreach (($setting->auto_generate_columns ?? []) as $columnName) {
            if (! isset($data[$columnName])) {
                $data[$columnName] = $setting->generateUniqueValue($columnName, (string) $record->source_guid);
            }
        }

        try {
            // Real INSERT attempt — same reasoning as the update branch
            // above. This is what actually catches 'field X doesn't have
            // a default value' / 'duplicate entry for key Y' before a
            // real sync run hits them thousands of times.
            $created = $modelClass::create($data + $defaults);

            return [
                'record_name' => $record->name,
                'action' => 'would_create',
                'detail' => 'رح ينشئ منتج جديد (تصنيف: '.($categoryId ?? 'افتراضي').')',
                'match_value' => $matchValueForLog,
            ];
        } catch (\Throwable $e) {
            // Mirror the Observer's general recovery — ANY duplicate-key
            // violation on create, not just slug specifically, means the
            // row already exists and should resolve to an update.
            // Without this, the dry run would show a scary 'error' for
            // exactly the case that actually self-heals in production.
            $isDuplicateKey = str_contains($e->getMessage(), 'Duplicate entry')
                || str_contains($e->getMessage(), '1062')
                || $e->getCode() === '23000';

            if ($isDuplicateKey) {
                $recovered = $this->findByAnyKnownIdentity($modelClass, $setting, $data);

                if ($recovered) {
                    return [
                        'record_name' => $record->name,
                        'action' => 'would_update',
                        'detail' => "رح يتحدّث المنتج #{$recovered->getKey()} (استرجاع عبر تعارض unique — كان بيفشل قبل هالإصلاح)",
                        'match_value' => $matchValueForLog,
                    ];
                }
            }

            return [
                'record_name' => $record->name,
                'action' => 'error',
                'detail' => $e->getMessage(),
                'match_value' => $matchValueForLog,
            ];
        }
    }

    /**
     * Mirrors SyncedRecordBridgeObserver::resolveFieldValue() — see its
     * docblock for the full reasoning. Kept as a separate copy for the
     * same reason the rest of this class duplicates the Observer's
     * logic.
     */
    private function resolveFieldValue(array $recordArray, string|array $sourceField): mixed
    {
        $candidates = is_array($sourceField) ? $sourceField : [$sourceField];

        foreach ($candidates as $path) {
            $value = Arr::get($recordArray, $path);
            if (! blank($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Mirrors SyncedRecordBridgeObserver::findByAnyKnownIdentity() —
     * tries every value we independently have on hand for this exact
     * record, in order of trustworthiness, stopping at the first hit.
     * Kept as a separate copy rather than a shared method for the same
     * reason the rest of this class duplicates the Observer's logic
     * (see class docblock) — dry-run mode should never risk destabilizing
     * the production-critical Observer for the sake of DRY.
     */
    private function findByAnyKnownIdentity(string $modelClass, BridgeSetting $setting, array $data): ?\Illuminate\Database\Eloquent\Model
    {
        $candidates = [
            $setting->source_number_column,
            $setting->match_target,
            $setting->auto_slug_column,
        ];

        foreach ($candidates as $column) {
            if (blank($column) || ! array_key_exists($column, $data) || blank($data[$column])) {
                continue;
            }

            $found = $modelClass::where($column, $data[$column])->first();
            if ($found) {
                return $found;
            }
        }

        return null;
    }
}
