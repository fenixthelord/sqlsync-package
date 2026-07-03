<?php

namespace SqlSync\LaravelSqlSync;

use Illuminate\Support\ServiceProvider;
use SqlSync\LaravelSqlSync\Services\SyncService;
use SqlSync\LaravelSqlSync\Services\AgentAuthService;
use SqlSync\LaravelSqlSync\Console\InstallCommand;
use SqlSync\LaravelSqlSync\Console\MakeTenantCommand;
use SqlSync\LaravelSqlSync\Models\SyncedRecord;
use SqlSync\LaravelSqlSync\Observers\SyncedRecordBridgeObserver;

class SqlSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sqlsync.php', 'sqlsync');

        $this->app->singleton(SyncService::class);
        $this->app->singleton(AgentAuthService::class);
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
            // Publish config
            $this->publishes([
                __DIR__ . '/../config/sqlsync.php' => config_path('sqlsync.php'),
            ], 'sqlsync-config');

            // Publish migrations (optional - already auto-loaded)
            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'sqlsync-migrations');

            // Register artisan commands
            $this->commands([
                InstallCommand::class,
                MakeTenantCommand::class,
            ]);
        }
    }
}
