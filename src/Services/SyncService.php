<?php

namespace SqlSync\LaravelSqlSync\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SqlSync\LaravelSqlSync\Models\SyncedRecord;
use SqlSync\LaravelSqlSync\Models\SyncAgent;
use SqlSync\LaravelSqlSync\Models\SyncLog;
use SqlSync\LaravelSqlSync\Contracts\PresetContract;

class SyncService
{
    public function process(
        string $preset,
        array $records,
        string $agentId,
        ?int $companyId = null
    ): array {
        $presetClass = config("sqlsync.presets.{$preset}");

        if (! $presetClass || ! class_exists($presetClass)) {
            throw new \InvalidArgumentException("Unknown SqlSync preset: [{$preset}]");
        }

        /** @var PresetContract $handler */
        $handler = new $presetClass();

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        DB::transaction(function () use (
            $handler, $records, $preset, $agentId, $companyId,
            &$inserted, &$updated, &$skipped
        ) {
            foreach (array_chunk($records, config('sqlsync.sync.batch_size', 500)) as $batch) {
                foreach ($batch as $raw) {
                    try {
                        $mapped = $handler->map($raw);

                        if (! isset($mapped['source_guid'])) {
                            $skipped++;
                            continue;
                        }

                        $existing = SyncedRecord::where('source_guid', $mapped['source_guid'])
                            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
                            ->first();

                        $payload = array_merge($mapped, [
                            'preset'     => $preset,
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

        // Update agent stats
        $this->updateAgentStats($agentId, $companyId, $inserted + $updated);

        // Write log entry
        if (config('sqlsync.sync.log_enabled')) {
            SyncLog::create([
                'agent_id'   => $agentId,
                'company_id' => $companyId,
                'preset'     => $preset,
                'inserted'   => $inserted,
                'updated'    => $updated,
                'skipped'    => $skipped,
                'status'     => 'success',
                'synced_at'  => now(),
            ]);
        }

        return compact('inserted', 'updated', 'skipped');
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
