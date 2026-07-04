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

        if (blank($matchValue) || blank($setting->match_target)) {
            $this->log($record, 'skipped', 'missing_match', "الحقل '{$setting->match_source}' فاضي بهاد السجل.");

            Log::info('SqlSync bridge: skipped — match source field is empty on this record.', [
                'match_source' => $setting->match_source,
                'record_id' => $record->id,
                'name' => $record->name,
            ]);

            return;
        }

        /** @var \Illuminate\Database\Eloquent\Model $existing */
        $existing = $modelClass::where($setting->match_target, $matchValue)->first();

        $data = [];
        foreach (($setting->fields ?? []) as $targetColumn => $sourceField) {
            $data[$targetColumn] = Arr::get($recordArray, $sourceField);
        }

        if ($existing) {
            // Only the mapped columns are touched — mall-owned fields
            // (images, description, category, etc.) are never overwritten,
            // and an already-assigned category is never re-resolved.
            try {
                $existing->update($data);
                $this->log($record, 'updated', null, null, $modelClass, $existing->getKey(), $matchValue);
            } catch (\Throwable $e) {
                $this->log($record, 'skipped', 'db_error', $e->getMessage(), $modelClass, null, $matchValue);

                Log::warning('SqlSync bridge: skipped updating product — a mapped field violated a database constraint.', [
                    'match_value' => $matchValue,
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
            $this->log($record, 'skipped', 'missing_defaults', 'قيمة افتراضية إجبارية ناقصة (مثل category_id) ولا يوجد مصدر تلقائي لها.', null, null, $matchValue);

            Log::info('SqlSync bridge: skipped creating product — missing required default.', [
                'match_value' => $matchValue,
                'name' => $record->name,
            ]);

            return;
        }

        $data[$setting->match_target] = $matchValue;

        try {
            $created = $modelClass::create(array_merge($data, $defaults));
            $this->log($record, 'created', null, null, $modelClass, $created->getKey(), $matchValue);
        } catch (\Throwable $e) {
            // A single record with, say, a missing price or a duplicate
            // SKU must never abort the whole sync/re-apply run for
            // everyone else — log it and move on.
            $this->log($record, 'skipped', 'db_error', $e->getMessage(), $modelClass, null, $matchValue);

            Log::warning('SqlSync bridge: skipped creating product — a mapped field violated a database constraint.', [
                'match_value' => $matchValue,
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
