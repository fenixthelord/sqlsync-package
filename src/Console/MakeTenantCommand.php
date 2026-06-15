<?php

namespace SqlSync\LaravelSqlSync\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeTenantCommand extends Command
{
    protected $signature   = 'sqlsync:make-tenant {name : Company name}';
    protected $description = 'Register a new tenant company for SqlSync';

    public function handle(): void
    {
        if (! config('sqlsync.multi_tenant')) {
            $this->warn('Multi-tenant mode is disabled. Set SQLSYNC_MULTI_TENANT=true in .env first.');
            return;
        }

        $name = $this->argument('name');
        $this->info("Registering tenant: {$name}");

        // This command is a helper — the actual implementation depends on
        // the host application's company/tenant model. We output a guide.
        $this->newLine();
        $this->line('Add a company to your companies table, then use <comment>company_id</comment>');
        $this->line('in every request from the Windows Agent.');
        $this->newLine();
        $this->line('Agent request example:');
        $this->line(json_encode([
            'preset'     => 'al_ameen',
            'company_id' => 1,
            'records'    => ['...'],
        ], JSON_PRETTY_PRINT));
    }
}
