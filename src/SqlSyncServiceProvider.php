<?php

namespace SqlSync\LaravelSqlSync;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use SqlSync\LaravelSqlSync\Console\GenerateLicenseKeypairCommand;
use SqlSync\LaravelSqlSync\Console\ImportLegacyLicenseCommand;
use SqlSync\LaravelSqlSync\Console\InstallCommand;
use SqlSync\LaravelSqlSync\Console\IssueLicenseCommand;
use SqlSync\LaravelSqlSync\Console\MakeTenantCommand;
use SqlSync\LaravelSqlSync\Console\PruneLogsCommand;
use SqlSync\LaravelSqlSync\Console\ReapplyBridgeCommand;
use SqlSync\LaravelSqlSync\Models\SyncedRecord;
use SqlSync\LaravelSqlSync\Observers\SyncedRecordBridgeObserver;
use SqlSync\LaravelSqlSync\Services\AccountingCommerceStore;
use SqlSync\LaravelSqlSync\Services\AgentAuthService;
use SqlSync\LaravelSqlSync\Services\LicenseService;
use SqlSync\LaravelSqlSync\Services\SyncService;

class SqlSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sqlsync.php', 'sqlsync');

        $this->app->singleton(SyncService::class);
        $this->app->singleton(AccountingCommerceStore::class);
        $this->app->singleton(AgentAuthService::class);
        $this->app->singleton(LicenseService::class);
    }

    public function boot(): void
    {
        // Routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/agent.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        // Migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Product Bridge — auto-registered so no project ever needs to
        // manually create/register an Observer for this to work. Entirely
        // inert until someone enables it from Filament -> Product Bridge.
        SyncedRecord::observe(SyncedRecordBridgeObserver::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sqlsync.php' => config_path('sqlsync.php'),
            ], 'sqlsync-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'sqlsync-migrations');

            $this->commands([
                InstallCommand::class,
                MakeTenantCommand::class,
                ReapplyBridgeCommand::class,
                GenerateLicenseKeypairCommand::class,
                IssueLicenseCommand::class,
                ImportLegacyLicenseCommand::class,
                PruneLogsCommand::class,
            ]);

            // The package already owns the retention policy, so cleanup must
            // also be package-owned. Host apps only need their normal Laravel
            // scheduler runner; no SqlSync-specific cron entry is required.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('sqlsync:prune-logs')
                    ->dailyAt('03:15')
                    ->withoutOverlapping();
            });
        }
    }
}
