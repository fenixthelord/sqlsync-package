<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Observers;

use Illuminate\Database\Eloquent\Model;
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

        // ── Sticky link: once a source item has been bridged to a
        // Product (by ANY method — barcode, fallback, whatever), we
        // remember the link on the SyncedRecord itself. Every future
        // sync of the SAME source item goes straight to that Product,
        // no re-matching by barcode/name at all.
        //
        // Why this matters: barcode and name are VALUES that can change
        // at the source (a supplier finally prints a barcode, an item
        // gets renamed, a barcode gets corrected). Re-deriving identity
        // from those values on every sync means any such change looks
        // like "a brand new item" and produces a duplicate Product
        // instead of updating the existing one. source_guid (Al-Bayan's
        // own internal item number) never changes for the life of the
        // item, so anchoring to it — once established — is what makes
        // updates resilient to any amount of source-side editing.
        // ── Layer 1 (strongest): identity stored directly ON the product
        // itself, in source_number_column. Checked FIRST because it's
        // the most durable link available — it survives even a full
        // wipe of sqlsync_records (e.g. Danger Zone reset without also
        // wiping Products), since the identity lives on the product row,
        // not on SqlSync's own bookkeeping table. Requires the admin to
        // configure source_number_column and add that column to their
        // Products table — optional, but the strongest guarantee
        // available once set up.
        if ($setting->source_number_column) {
            $existing = $modelClass::where($setting->source_number_column, $record->source_guid)->first();
        }

        // ── Layer 2: the sqlsync_records-side sticky link. Faster to
        // check (no dependency on the admin having configured Layer 1),
        // but lost if sqlsync_records itself is ever wiped.
        if (! $existing && $record->product_id) {
            $existing = $modelClass::find($record->product_id);
            // If the linked Product was deleted independently (e.g. a
            // human removed it from the website directly), fall through
            // to normal matching below rather than silently doing
            // nothing — the item may need to be re-created.
        }

        // ── Layer 3: ordinary matching (barcode, or the composite
        // fallback) — the ONLY layer available on true first contact,
        // when neither of the above has ever been established yet.
        if (! $existing) {
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
            } else {
                /** @var \Illuminate\Database\Eloquent\Model|null $existing */
                $existing = $modelClass::where($setting->match_target, $matchValue)->first();
            }
        }

        $matchValueForLog = $usingFallback
            ? $setting->describeFallbackKey($recordArray)
            : $matchValue;

        $data = [];
        foreach (($setting->fields ?? []) as $targetColumn => $sourceField) {
            $value = $this->resolveFieldValue($recordArray, $sourceField);

            // Deliberately skip setting the key at all when the source
            // resolves to nothing — NOT $data[$targetColumn] = null.
            // $data + $defaults (used at create time) only fills keys
            // that are genuinely ABSENT from $data; a key present with
            // a null value blocks the default from ever applying, even
            // when create_defaults has an explicit fallback configured
            // for it (e.g. price => 0 for items Al-Bayan has no pricing
            // details for yet — a real, valid state: the item exists
            // but wasn't fully priced when it was entered).
            if ($value !== null) {
                $data[$targetColumn] = $value;
            }
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

            // Backfill the durable product-side identity — critical for
            // products that existed before this feature shipped, or
            // that were JUST matched via barcode/fallback for the first
            // time (Layer 3 in the identity resolution above). Once
            // written, this specific product becomes immune to future
            // sqlsync_records wipes — Layer 1 will find it directly next
            // time, with zero reliance on barcode/name matching.
            if ($setting->source_number_column) {
                $data[$setting->source_number_column] = $record->source_guid;
            }

            // Opt-in only (default false — the original, protective
            // behavior). Most stores want manual category reassignments
            // on the website to stick permanently; this exists for the
            // minority who want the website to always mirror the
            // accounting software's CURRENT classification and don't do
            // manual overrides on the website side.
            if ($setting->category_reresolve_on_update) {
                $categoryId = $setting->resolveCategoryId($recordArray);
                if ($categoryId !== null && $setting->category_target_field) {
                    $data[$setting->category_target_field] = $categoryId;
                }
            }

            try {
                $existing->update($data);

                // Persist the sticky link if this is the first time we're
                // seeing a successful match for this SyncedRecord (e.g.
                // records that existed before this feature shipped, or
                // records that fell through to fresh matching because
                // their previously-linked Product was deleted). Uses
                // a raw query update rather than $record->update() to
                // avoid re-triggering this same 'saved' observer
                // recursively on the SyncedRecord itself.
                if ($record->product_id !== $existing->getKey()) {
                    SyncedRecord::whereKey($record->id)->update(['product_id' => $existing->getKey()]);
                }

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

        // Auto-slug — deliberately computed AFTER the 'fields' loop so it
        // always wins, even if an admin still has a raw field (like
        // 'code') mapped to the same column from before this feature
        // existed. Only applied on CREATE, matching category_id's
        // treatment — an existing product's slug is never touched by a
        // later sync, so a source-side name change doesn't silently
        // break bookmarked/indexed URLs.
        if ($setting->auto_slug_column) {
            $data[$setting->auto_slug_column] = $setting->generateSafeSlug(
                (string) $record->name,
                (string) $record->source_guid,
            );
        }

        // Establish the durable product-side identity from the very
        // first creation — this new product is now immune to any future
        // sqlsync_records wipe, since Layer 1 of the identity resolution
        // above will find it directly by this column, with zero reliance
        // on barcode/name ever again.
        if ($setting->source_number_column) {
            $data[$setting->source_number_column] = $record->source_guid;
        }

        // Auto-generated unique values — generalized fallback for any
        // required column the admin marked for auto-generation because
        // no real synced data covers it confidently. Only fills columns
        // NOT already set by 'fields'/source_number_column/auto_slug/
        // category resolution above — never overrides real data.
        foreach (($setting->auto_generate_columns ?? []) as $columnName) {
            if (! isset($data[$columnName])) {
                $data[$columnName] = $setting->generateUniqueValue($columnName, (string) $record->source_guid);
            }
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
        //
        // For a BRAND-NEW row specifically, leaving match_target unset
        // is a real problem: it's very commonly a NOT NULL and/or
        // UNIQUE column (e.g. 'sku') on the host app's own Products
        // table, and INSERT will simply fail for every barcode-less
        // item otherwise (confirmed: SQLSTATE[HY000] 1364 'sku' doesn't
        // have a default value, on every fallback-matched create).
        //
        // Fix: synthesize a value from the record's own source_guid,
        // which is guaranteed unique per record (it's the sync system's
        // own primary key — e.g. 'bayan-21372'). Prefixed so it's
        // visually obvious in an admin UI that this isn't a real
        // barcode/SKU from the supplier.
        if (! blank($matchValue)) {
            $data[$setting->match_target] = $matchValue;
        } elseif ($usingFallback) {
            $data[$setting->match_target] = 'SS-'.$record->source_guid;
        }

        try {
            // CRITICAL: must be $data + $defaults, NOT array_merge($data, $defaults).
            // array_merge() has the SECOND array win on key collisions — so a
            // correctly-resolved category_id in $data (from resolveCategoryId
            // above) was being silently overwritten by create_defaults' fallback
            // value on every single create, regardless of whether resolution
            // succeeded. The + operator has the LEFT operand win instead, which
            // is the actual intended semantics: defaults fill gaps, never clobber
            // a value $data already has.
            $created = $modelClass::create($data + $defaults);

            // Same reasoning as the update path above — anchor this
            // SyncedRecord to the Product we just created, so any future
            // change to barcode/name/anything used for matching updates
            // THIS product instead of creating a duplicate.
            SyncedRecord::whereKey($record->id)->update(['product_id' => $created->getKey()]);

            $this->log($record, 'created', null, null, $modelClass, $created->getKey(), $matchValueForLog);
        } catch (\Throwable $e) {
            // General principle, not a slug-specific patch: ANY unique-
            // constraint violation on a CREATE attempt means the row
            // already exists somewhere — for ANY column, not just
            // auto_slug_column. If we hit a duplicate-key error here at
            // all, it's proof one of our three identity layers
            // (source_number_column, the sticky link, barcode/fallback
            // matching) failed to find a row that genuinely exists —
            // most commonly residual data from a Danger Zone reset that
            // wiped sqlsync_records/category tracking without also
            // wiping Products. This must ALWAYS resolve to an update,
            // never a permanent hard failure, regardless of which
            // specific column the database complained about.
            //
            // Recovery: try every identifying value we independently
            // have on hand for this exact record — in order of
            // trustworthiness — and take the first one that finds a row.
            $isDuplicateKey = str_contains($e->getMessage(), 'Duplicate entry')
                || str_contains($e->getMessage(), '1062')
                || $e->getCode() === '23000';

            if ($isDuplicateKey) {
                $recovered = $this->findByAnyKnownIdentity($modelClass, $setting, $data, $matchValue);

                if ($recovered) {
                    try {
                        $recovered->update($data);
                        SyncedRecord::whereKey($record->id)->update(['product_id' => $recovered->getKey()]);

                        $this->log($record, 'updated', null,
                            'استُرجع عبر تعارض قيد unique — الطبقات الثلاث الأساسية فشلت بإيجاده مسبقاً، غالباً من بيانات متبقية من reset سابق.',
                            $modelClass, $recovered->getKey(), $matchValueForLog);

                        return;
                    } catch (\Throwable) {
                        // Even the recovery update failed (a genuinely
                        // different problem) — fall through to normal
                        // failure logging below rather than looping
                        // forever or throwing uncaught.
                    }
                }
            }

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

    /**
     * Last-resort identity recovery, used only when a CREATE attempt hit
     * a duplicate-key violation — proof the row already exists, but our
     * three normal identity layers all failed to find it beforehand.
     *
     * Tries every value we independently have on hand for this exact
     * record, in order of how trustworthy each one is as a unique
     * identifier — source_number_column first (the strongest guarantee
     * the Bridge offers), then match_target (usually a real barcode),
     * then auto_slug_column (deterministic per name+source_guid, so a
     * match here is still solid proof even though it's a weaker signal
     * in principle than a real barcode). Stops at the first hit.
     *
     * @param array<string, mixed> $data the fully-built column => value
     *   array that was about to be passed to create()
     */
    private function findByAnyKnownIdentity(
        string $modelClass,
        BridgeSetting $setting,
        array $data,
        ?string $matchValue,
    ): ?Model {
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

    /**
     * Resolves a field mapping's value against the synced record —
     * supports both a single source path (string, the original/
     * backward-compatible format) and a LIST of candidate paths (array),
     * trying each in order and using the first one that isn't genuinely
     * blank.
     *
     * Direct fix for a real production case: a required 'price' column
     * failed with 'Column price cannot be null' for a specific product
     * whose extra_data.price_4 happened to be empty — even though
     * price_4 IS the right price tier for MOST products in this
     * catalogue. Different items apparently populate different price
     * columns in the same accounting software, which we'd already
     * confirmed manually during earlier debugging (Saati Pharmacy's
     * real retail price lived in price_4, not the more obviously-named
     * sel_price) — this generalizes that reality into a first-class
     * mapping capability instead of only ever trying ONE column and
     * failing hard when it happens to be empty for a particular item.
     *
     * Uses Laravel's blank() semantics deliberately — a genuine 0 is
     * NOT blank (a real, intentional zero price/quantity is respected),
     * only null/''/[] triggers falling through to the next candidate.
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
