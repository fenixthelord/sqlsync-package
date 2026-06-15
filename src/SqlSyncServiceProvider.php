<?php

namespace SqlSync\LaravelSqlSync;

use Illuminate\Support\ServiceProvider;
use SqlSync\LaravelSqlSync\Services\SyncService;
use SqlSync\LaravelSqlSync\Services\AgentAuthService;
use SqlSync\LaravelSqlSync\Console\InstallCommand;
use SqlSync\LaravelSqlSync\Console\MakeTenantCommand;

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
