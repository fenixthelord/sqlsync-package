<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PruneLogsCommand extends Command
{
    protected $signature = 'sqlsync:prune-logs
        {--sync-days= : Override sqlsync_logs retention days}
        {--bridge-days= : Override sqlsync_bridge_logs retention days}
        {--chunk=1000 : Number of rows deleted per batch}';

    protected $description = 'Prune high-volume SqlSync operational logs according to the configured retention policy';

    public function handle(): int
    {
        $syncDays = $this->retentionDays('sync-days', 'sqlsync.sync.log_retention_days', 30);
        $bridgeDays = $this->retentionDays('bridge-days', 'sqlsync.bridge.log_retention_days', 14);
        $chunkSize = max(100, (int) $this->option('chunk'));

        $bridgeSuccessDeleted = 0;

        // Successful per-record bridge rows are intentionally disabled by
        // default. When that policy is active, old successful rows have no
        // operational value either, so remove them immediately instead of
        // waiting for the age-based retention window to expire.
        if (! config('sqlsync.bridge.log_successful_actions', false)) {
            $bridgeSuccessDeleted = $this->deleteInChunks(
                DB::table('sqlsync_bridge_logs')
                    ->whereIn('action', ['created', 'updated']),
                'sqlsync_bridge_logs',
                $chunkSize,
            );
        }

        $bridgeExpiredDeleted = $this->deleteInChunks(
            DB::table('sqlsync_bridge_logs')
                ->where('created_at', '<', now()->subDays($bridgeDays)),
            'sqlsync_bridge_logs',
            $chunkSize,
        );

        $syncExpiredDeleted = $this->deleteInChunks(
            DB::table('sqlsync_logs')
                ->where('synced_at', '<', now()->subDays($syncDays)),
            'sqlsync_logs',
            $chunkSize,
        );

        $this->components->info(sprintf(
            'Pruned %d bridge success rows, %d expired bridge rows, and %d expired sync rows.',
            $bridgeSuccessDeleted,
            $bridgeExpiredDeleted,
            $syncExpiredDeleted,
        ));

        return self::SUCCESS;
    }

    private function retentionDays(string $option, string $configKey, int $default): int
    {
        $override = $this->option($option);
        $days = $override !== null
            ? (int) $override
            : (int) config($configKey, $default);

        return max(1, $days);
    }

    private function deleteInChunks(Builder $query, string $table, int $chunkSize): int
    {
        $deleted = 0;

        do {
            $ids = (clone $query)
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table($table)
                ->whereIn('id', $ids->all())
                ->delete();
        } while ($ids->count() === $chunkSize);

        return $deleted;
    }
}
