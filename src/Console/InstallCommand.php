<?php

namespace SqlSync\LaravelSqlSync\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature   = 'sqlsync:install';
    protected $description = 'Install SqlSync — publish config and run migrations';

    public function handle(): void
    {
        $this->info('Installing SqlSync...');

        // Publish config
        $this->callSilently('vendor:publish', [
            '--tag' => 'sqlsync-config',
        ]);
        $this->line('  <fg=green>✓</> Config published → config/sqlsync.php');

        // Run migrations
        $this->callSilently('migrate');
        $this->line('  <fg=green>✓</> Migrations applied');

        $this->newLine();
        $this->info('SqlSync installed successfully!');
        $this->newLine();
        $this->line('Add to your <comment>.env</comment>:');
        $this->line('  SQLSYNC_AGENT_SECRET=your-strong-secret-here');
        $this->line('  SQLSYNC_MULTI_TENANT=false');
        $this->newLine();
        $this->line('Agent sync endpoint:');
        $this->line('  <comment>POST /sqlsync/agent/sync</comment>');
        $this->newLine();
        $this->line('Mobile API endpoint:');
        $this->line('  <comment>GET /sqlsync/api/v1/records</comment>');
    }
}
