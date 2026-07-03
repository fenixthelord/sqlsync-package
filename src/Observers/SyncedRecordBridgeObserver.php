<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Observers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
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
            $existing->update($data);

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
            Log::info('SqlSync bridge: skipped creating product — missing required default.', [
                'match_value' => $matchValue,
                'name' => $record->name,
            ]);

            return;
        }

        $data[$setting->match_target] = $matchValue;
        $modelClass::create(array_merge($data, $defaults));
    }
}
