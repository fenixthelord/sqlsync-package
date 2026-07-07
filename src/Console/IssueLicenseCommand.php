<?php

namespace SqlSync\LaravelSqlSync\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use SqlSync\LaravelSqlSync\Models\License;

class IssueLicenseCommand extends Command
{
    protected $signature = 'sqlsync:issue-license
                            {--days=365 : Validity period in days}
                            {--company= : Company ID (multi-tenant)}
                            {--customer= : Customer name for record-keeping}
                            {--notes= : Free-form notes}';

    protected $description = 'Issue a new SqlSync license key';

    public function handle(): int
    {
        $key = $this->generateKey();
        $days = (int) $this->option('days');

        $license = License::create([
            'license_key' => $key,
            'company_id'  => $this->option('company'),
            'expires_at'  => now()->addDays($days),
            'status'      => 'active',
            'meta'        => array_filter([
                'customer' => $this->option('customer'),
                'notes'    => $this->option('notes'),
            ]),
        ]);

        $this->info('✓ License issued');
        $this->newLine();
        $this->line('  Key       : ' . $license->license_key);
        $this->line('  Expires   : ' . $license->expires_at->toDateString());
        $this->line('  Days      : ' . $days);

        if ($this->option('customer')) {
            $this->line('  Customer  : ' . $this->option('customer'));
        }

        return self::SUCCESS;
    }

    /**
     * Format: XXXX-XXXX-XXXX-XXXX (16 chars, uppercase alphanumeric, no ambiguous chars)
     */
    private function generateKey(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no O/0/I/1
        $len = strlen($alphabet);

        $chunks = [];
        for ($c = 0; $c < 4; $c++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= $alphabet[random_int(0, $len - 1)];
            }
            $chunks[] = $chunk;
        }

        return implode('-', $chunks);
    }
}
