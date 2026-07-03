<?php

namespace SqlSync\LaravelSqlSync\Console;

use Illuminate\Console\Command;
use SqlSync\LaravelSqlSync\Models\SyncedRecord;

class ReapplyBridgeCommand extends Command
{
    protected $signature = 'sqlsync:reapply-bridge {--company= : Only re-apply for this company_id}';

    protected $description = 'Re-fire the Product Bridge on every existing SyncedRecord — use this for large catalogs instead of the Filament button, since it has no execution time limit.';

    public function handle(): int
    {
        $query = SyncedRecord::query();

        if ($companyId = $this->option('company')) {
            $query->where('company_id', $companyId);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->warn('No synced records found.');

            return self::SUCCESS;
        }

        $this->info("Re-applying bridge to {$total} record(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $done = 0;
        $failed = 0;

        $query->orderBy('id')->chunkById(500, function ($records) use ($bar, &$done, &$failed) {
            foreach ($records as $record) {
                try {
                    // synced_at is re-stamped so the model is "dirty" and
                    // Eloquent actually fires the saved event — a no-op
                    // save() on an unchanged model skips events entirely.
                    $record->synced_at = now();
                    $record->save();
                    $done++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->line("<fg=yellow>skipped</> #{$record->id} ({$record->name}): {$e->getMessage()}");
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done — {$done} succeeded, {$failed} skipped.");

        return self::SUCCESS;
    }
}
