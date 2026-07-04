<?php

namespace SqlSync\LaravelSqlSync\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use SqlSync\LaravelSqlSync\Models\SyncedRecord;

class ReapplyBridgeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(public ?int $companyId = null)
    {
    }

    public static function cacheKey(?int $companyId = null): string
    {
        return 'sqlsync:reapply-progress:' . ($companyId ?? 'default');
    }

    public function handle(): void
    {
        $key = static::cacheKey($this->companyId);
        $query = SyncedRecord::query();

        if ($this->companyId) {
            $query->where('company_id', $this->companyId);
        }

        $total = $query->count();

        $progress = ['status' => 'running', 'done' => 0, 'failed' => 0, 'total' => $total];
        Cache::put($key, $progress, now()->addHours(2));

        if ($total === 0) {
            Cache::put($key, ['status' => 'finished', 'done' => 0, 'failed' => 0, 'total' => 0], now()->addHours(2));

            return;
        }

        $query->orderBy('id')->chunkById(500, function ($records) use (&$progress, $key) {
            foreach ($records as $record) {
                try {
                    $record->synced_at = now();
                    $record->save();
                    $progress['done']++;
                } catch (\Throwable $e) {
                    $progress['failed']++;
                }
            }

            // Updated after each chunk (not each row) to keep cache writes cheap.
            Cache::put($key, $progress, now()->addHours(2));
        });

        $progress['status'] = 'finished';
        Cache::put($key, $progress, now()->addHours(2));
    }
}
