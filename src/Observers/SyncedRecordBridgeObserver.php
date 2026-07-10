<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Observers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use SqlSync\LaravelSqlSync\Models\BridgeLog;
use SqlSync\LaravelSqlSync\Models\BridgeSetting;
use SqlSync\LaravelSqlSync\Models\SyncedRecord;

/**
 * Bridges SqlSync's own storage (sqlsync_records) into whatever product
 * model the host app configures. Lives inside the package (not the host
 * app) and is auto-registered by SqlSyncServiceProvider — nothing to copy
 * or wire up manually per project. Every project-specific detail (target
 * model, matching column, field mapping, auto-category rules) is read
 * from BridgeSetting, configured visually from
 * Filament -> SqlSync -> Product Bridge.
 *
 * Every outcome (created / updated / skipped) is also written to
 * sqlsync_bridge_logs so Filament -> SqlSync -> Bridge Activity can show
 * a readable history instead of digging through storage/logs.
 */
class SyncedRecordBridgeObserver
{
    public function saved(SyncedRecord $record): void
    {
        $setting = BridgeSetting::current($record->company_id);

        if (! $setting->enabled) {
            return;
        }

        $modelClass = $setting->target_model;
        if (! $modelClass || ! class_exists($modelClass)) {
            return;
        }

        $recordArray = $record->toArray();
        $matchValue = Arr::get($recordArray, (string) $setting->match_source);

        $usingFallback = false;
        $existing = null;

        if (blank($matchValue) || blank($setting->match_target)) {
            // Primary match key (usually barcode) is blank on this
            // record — try the composite fallback (e.g. name + brand)
            // before giving up entirely. Common for pharmacy / health
            // items that are sold without a printed barcode.
            $fallback = $setting->resolveFallbackMatch($modelClass, $recordArray);

            if (! $fallback['query_ok']) {
                $reason = empty($setting->fallback_match_fields)
                    ? "الحقل '{$setting->match_source}' فاضي بهاد السجل، ولا يوجد مطابقة احتياطية معرّفة."
                    : "الحقل '{$setting->match_source}' فاضي، والمطابقة الاحتياطية ({$setting->describeFallbackKey($recordArray)}) غير مكتملة.";

                $this->log($record, 'skipped', 'missing_match', $reason);

                Log::info('SqlSync bridge: skipped — no usable match key (primary blank, fallback unavailable).', [
                    'match_source' => $setting->match_source,
                    'record_id' => $record->id,
                    'name' => $record->name,
                ]);

                return;
            }

            $usingFallback = true;
            $existing = $fallback['existing'];
            // matchValue stays null here — there's no single scalar value
            // to log/store as the "match" when it's a composite key. The
            // log's match_value column records the composite description
            // instead (see $this->log call below for fallback cases).
        } else {
            /** @var \Illuminate\Database\Eloquent\Model $existing */
            $existing = $modelClass::where($setting->match_target, $matchValue)->first();
        }

        $matchValueForLog = $usingFallback
            ? $setting->describeFallbackKey($recordArray)
            : $matchValue;

        $data = [];
        foreach (($setting->fields ?? []) as $targetColumn => $sourceField) {
            $data[$targetColumn] = Arr::get($recordArray, $sourceField);
        }

        if ($existing) {
            // Only the mapped columns are touched — mall-owned fields
            // (images, description, category, etc.) are never overwritten,
            // and an already-assigned category is never re-resolved.
            //
            // If this record was matched via the fallback key AND now
            // has a real barcode (a supplier finally printed one), write
            // it — a barcode discovered later should stick. Never
            // overwrite an existing match_target value with null.
            if (! blank($matchValue)) {
                $data[$setting->match_target] = $matchValue;
            }

            try {
                $existing->update($data);
                $this->log($record, 'updated', null, null, $modelClass, $existing->getKey(), $matchValueForLog);
            } catch (\Throwable $e) {
                $this->log($record, 'skipped', 'db_error', $e->getMessage(), $modelClass, null, $matchValueForLog);

                Log::warning('SqlSync bridge: skipped updating product — a mapped field violated a database constraint.', [
                    'match_value' => $matchValueForLog,
                    'name' => $record->name,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        // Brand-new item — resolve (or auto-create) its category first,
        // since that's what lets creation succeed even when category_id
        // has no static default in 'create_defaults'.
        $categoryId = $setting->resolveCategoryId($recordArray);
        if ($categoryId !== null && $setting->category_target_field) {
            $data[$setting->category_target_field] = $categoryId;
        }

        $defaults = $setting->create_defaults ?? [];
        $missingRequired = collect($defaults)
            ->reject(fn ($value, $column) => $column === $setting->category_target_field && isset($data[$column]))
            ->contains(fn ($value) => blank($value));

        if ($missingRequired && $setting->skip_create_if_missing_defaults) {
            $this->log($record, 'skipped', 'missing_defaults', 'قيمة افتراضية إجبارية ناقصة (مثل category_id) ولا يوجد مصدر تلقائي لها.', null, null, $matchValueForLog);

            Log::info('SqlSync bridge: skipped creating product — missing required default.', [
                'match_value' => $matchValueForLog,
                'name' => $record->name,
            ]);

            return;
        }

        // Only stamp match_target when we have an actual scalar value —
        // never write null over it (a fallback match has no single
        // scalar value to store; the composite fields it used are
        // already part of $data via the normal 'fields' mapping if the
        // admin included them there too).
        if (! blank($matchValue)) {
            $data[$setting->match_target] = $matchValue;
        }

        try {
            $created = $modelClass::create(array_merge($data, $defaults));
            $this->log($record, 'created', null, null, $modelClass, $created->getKey(), $matchValueForLog);
        } catch (\Throwable $e) {
            // A single record with, say, a missing price or a duplicate
            // SKU must never abort the whole sync/re-apply run for
            // everyone else — log it and move on.
            $this->log($record, 'skipped', 'db_error', $e->getMessage(), $modelClass, null, $matchValueForLog);

            Log::warning('SqlSync bridge: skipped creating product — a mapped field violated a database constraint.', [
                'match_value' => $matchValueForLog,
                'name' => $record->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function log(
        SyncedRecord $record,
        string $action,
        ?string $reason = null,
        ?string $detail = null,
        ?string $targetModel = null,
        ?int $targetId = null,
        ?string $matchValue = null,
    ): void {
        try {
            BridgeLog::create([
                'company_id' => $record->company_id,
                'synced_record_id' => $record->id,
                'record_name' => $record->name,
                'match_value' => $matchValue,
                'action' => $action,
                'reason' => $reason,
                'detail' => $detail,
                'target_model' => $targetModel,
                'target_id' => $targetId,
            ]);
        } catch (\Throwable $e) {
            // Logging must never be the reason a sync fails.
            Log::debug('SqlSync bridge: failed to write BridgeLog entry: ' . $e->getMessage());
        }
    }
}
