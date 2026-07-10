<?php

namespace SqlSync\LaravelSqlSync\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SqlSync\LaravelSqlSync\Contracts\PresetContract;
use SqlSync\LaravelSqlSync\Models\CategoryNode;
use SqlSync\LaravelSqlSync\Models\SyncAgent;
use SqlSync\LaravelSqlSync\Models\SyncedRecord;
use SqlSync\LaravelSqlSync\Models\SyncLog;

class SyncService
{
    /**
     * Process one batch from the Agent.
     *
     * Idempotency:
     *   If we've already logged a completed batch with the same
     *   idempotency_key, we return that cached result instead of
     *   re-processing. That way a retry after a network hiccup produces
     *   the exact same counters and doesn't double-write anything —
     *   and the underlying SyncedRecord upsert-by-source_guid guarantees
     *   the DB itself stays correct even if the receipt check ever
     *   misses.
     *
     * @return array{inserted:int, updated:int, skipped:int, replay:bool}
     */
    public function process(
        string  $provider,
        ?string $dataset,
        array   $records,
        string  $agentId,
        ?int    $companyId = null,
        ?int    $batchIndex = null,
        ?int    $batchCount = null,
        ?string $idempotencyKey = null,
        ?string $watermark = null,
    ): array {

        // ── Replay short-circuit ────────────────────────────────────
        if ($idempotencyKey !== null) {
            $existing = SyncLog::where('idempotency_key', $idempotencyKey)
                ->where('agent_id', $agentId)
                ->first();

            if ($existing) {
                return [
                    'inserted' => (int) $existing->inserted,
                    'updated'  => (int) $existing->updated,
                    'skipped'  => (int) $existing->skipped,
                    'replay'   => true,
                ];
            }
        }

        // Category-tree nodes are reference data, not sellable products —
        // they never go through SyncedRecord/the Product Bridge Observer.
        // Routed separately so the two concerns (products vs. the
        // classification tree they're filed under) can't leak into each
        // other's logic.
        if ($dataset === 'categories') {
            return $this->processCategoryNodes(
                $records, $provider, $dataset, $agentId, $companyId,
                $batchIndex, $batchCount, $idempotencyKey, $watermark,
            );
        }

        // ── Resolve preset ──────────────────────────────────────────
        $presetClass = config("sqlsync.presets.{$provider}");
        if (! $presetClass || ! class_exists($presetClass)) {
            throw new \InvalidArgumentException("Unknown SqlSync provider: [{$provider}]");
        }

        /** @var PresetContract $handler */
        $handler = new $presetClass();

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        DB::transaction(function () use (
            $handler, $records, $provider, $dataset, $agentId, $companyId,
            &$inserted, &$updated, &$skipped
        ) {
            foreach (array_chunk($records, config('sqlsync.sync.batch_size', 500)) as $chunk) {
                foreach ($chunk as $raw) {
                    try {
                        // Presets that care about which dataset the row
                        // came from can implement mapDataset(); those
                        // that don't fall back to the plain map().
                        $mapped = method_exists($handler, 'mapDataset')
                            ? $handler->mapDataset($raw, $dataset)
                            : $handler->map($raw);

                        if (! isset($mapped['source_guid'])) {
                            $skipped++;
                            continue;
                        }

                        $existing = SyncedRecord::where('source_guid', $mapped['source_guid'])
                            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
                            ->first();

                        $payload = array_merge($mapped, [
                            'preset'     => $provider,
                            'agent_id'   => $agentId,
                            'company_id' => $companyId,
                            'synced_at'  => now(),
                        ]);

                        if ($existing) {
                            $existing->update($payload);
                            $updated++;
                        } else {
                            SyncedRecord::create($payload);
                            $inserted++;
                        }
                    } catch (\Throwable $e) {
                        $skipped++;
                        if (config('sqlsync.sync.log_enabled')) {
                            Log::warning('SqlSync: skipped record', [
                                'error'  => $e->getMessage(),
                                'record' => $raw,
                            ]);
                        }
                    }
                }
            }
        });

        $this->updateAgentStats($agentId, $companyId, $inserted + $updated);

        $this->writeSyncLog(
            $provider, $dataset, $agentId, $companyId,
            $batchIndex, $batchCount, $idempotencyKey, $watermark,
            $inserted, $updated, $skipped,
        );

        return compact('inserted', 'updated', 'skipped') + ['replay' => false];
    }

    /**
     * Upserts category-tree nodes (Al-Bayan's Kind=1 MatCard rows, or
     * the equivalent from a future preset) into sqlsync_category_nodes.
     * Matched by (company_id, source_num) — Al-Bayan's Num column is a
     * single sequence shared by both products and tree nodes, so it's
     * a safe natural key here the same way source_guid is for products.
     *
     * @return array{inserted:int, updated:int, skipped:int, replay:bool}
     */
    private function processCategoryNodes(
        array $records,
        string $provider,
        ?string $dataset,
        string $agentId,
        ?int $companyId,
        ?int $batchIndex,
        ?int $batchCount,
        ?string $idempotencyKey,
        ?string $watermark,
    ): array {
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        DB::transaction(function () use ($records, $agentId, $companyId, &$inserted, &$updated, &$skipped) {
            foreach ($records as $raw) {
                $sourceNum = $raw['source_num'] ?? null;
                $treeNum   = $raw['tree_num'] ?? null;
                $name      = $raw['name'] ?? null;

                if ($sourceNum === null || blank($treeNum) || blank($name)) {
                    $skipped++;

                    continue;
                }

                $payload = [
                    'agent_id'   => $agentId,
                    'company_id' => $companyId,
                    'source_num' => $sourceNum,
                    'tree_num'   => $treeNum,
                    'name'       => $name,
                    'synced_at'  => now(),
                ];

                $existing = CategoryNode::where('source_num', $sourceNum)
                    ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                    ->first();

                if ($existing) {
                    $existing->update($payload);
                    $updated++;
                } else {
                    CategoryNode::create($payload);
                    $inserted++;
                }
            }
        });

        $this->writeSyncLog(
            $provider, $dataset, $agentId, $companyId,
            $batchIndex, $batchCount, $idempotencyKey, $watermark,
            $inserted, $updated, $skipped,
        );

        return compact('inserted', 'updated', 'skipped') + ['replay' => false];
    }

    private function writeSyncLog(
        string $provider,
        ?string $dataset,
        string $agentId,
        ?int $companyId,
        ?int $batchIndex,
        ?int $batchCount,
        ?string $idempotencyKey,
        ?string $watermark,
        int $inserted,
        int $updated,
        int $skipped,
    ): void {
        if (! config('sqlsync.sync.log_enabled')) {
            return;
        }

        SyncLog::create([
            'agent_id'        => $agentId,
            'company_id'      => $companyId,
            'preset'          => $provider,
            'dataset'         => $dataset,
            'batch_index'     => $batchIndex,
            'batch_count'     => $batchCount,
            'idempotency_key' => $idempotencyKey,
            'high_watermark'  => $watermark,
            'inserted'        => $inserted,
            'updated'         => $updated,
            'skipped'         => $skipped,
            'status'          => 'success',
            'synced_at'       => now(),
        ]);
    }

    public function recordHeartbeat(string $agentId, ?int $companyId = null): void
    {
        SyncAgent::updateOrCreate(
            ['agent_id' => $agentId],
            [
                'company_id'     => $companyId,
                'last_heartbeat' => now(),
            ]
        );
    }

    public function getLogsForAgent(string $agentId, ?int $companyId = null): array
    {
        return SyncLog::where('agent_id', $agentId)
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->orderByDesc('synced_at')
            ->limit(50)
            ->get()
            ->toArray();
    }

    private function updateAgentStats(string $agentId, ?int $companyId, int $count): void
    {
        SyncAgent::updateOrCreate(
            ['agent_id' => $agentId],
            [
                'company_id'   => $companyId,
                'last_sync_at' => now(),
            ]
        );

        SyncAgent::where('agent_id', $agentId)
            ->increment('total_synced', $count);
    }
}
