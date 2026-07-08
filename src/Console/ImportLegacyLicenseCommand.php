<?php

namespace SqlSync\LaravelSqlSync\Console;

use Illuminate\Console\Command;
use SqlSync\LaravelSqlSync\Models\License;

/**
 * Imports a license key that was originally issued under the old HMAC
 * scheme (pre-Batch-A) so an already-deployed Agent can complete a real
 * online activation against this server after upgrading.
 *
 * Usage examples:
 *
 *   # Register the key EU Store already has, valid 1 year forward:
 *   php artisan sqlsync:import-legacy-license ABCD-EFGH-JKLM-NPQR \
 *       --customer="Euro Store" --days=365
 *
 *   # Register it and pre-bind to the machine that's already running it
 *   # (skips the "one-shot activation binds a machine" step):
 *   php artisan sqlsync:import-legacy-license ABCD-EFGH-JKLM-NPQR \
 *       --customer="Euro Store" --days=365 \
 *       --machine=abc123def456...  --agent-id=abc123def456...
 *
 * Idempotent: running twice on the same key is a no-op (with --force
 * you can update expiry / metadata on an existing row).
 */
class ImportLegacyLicenseCommand extends Command
{
    protected $signature = 'sqlsync:import-legacy-license
                            {key                : The license key the customer already has}
                            {--days=365         : Validity from today}
                            {--customer=        : Customer name for record-keeping}
                            {--company=         : Company ID (multi-tenant installs)}
                            {--machine=         : Pre-bind to this machine_id}
                            {--agent-id=        : Pre-bind to this agent_id}
                            {--force            : Update an existing row if the key already exists}';

    protected $description = 'Register a pre-existing (legacy HMAC) license key in the new signed-license system';

    public function handle(): int
    {
        $key = strtoupper(trim($this->argument('key')));
        if (! preg_match('/^[A-Z0-9\-]{4,32}$/', $key)) {
            $this->error("License key does not look valid: {$key}");
            return self::FAILURE;
        }

        $existing = License::where('license_key', $key)->first();

        if ($existing && ! $this->option('force')) {
            $this->error("License already exists (id={$existing->id}). Use --force to update.");
            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $meta = array_filter([
            'imported_from'    => 'legacy_hmac',
            'imported_at'      => now()->toIso8601String(),
            'customer'         => $this->option('customer'),
        ]);

        $data = [
            'license_key' => $key,
            'company_id'  => $this->option('company'),
            'machine_id'  => $this->option('machine'),
            'agent_id'    => $this->option('agent-id'),
            'expires_at'  => now()->addDays($days),
            'status'      => 'active',
            'meta'        => $meta,
        ];

        // Pre-bound imports skip straight to "activated"; unbound
        // imports wait for the Agent to call /license/activate.
        if ($this->option('machine')) {
            $data['activated_at']     = now();
            $data['last_verified_at'] = now();
        }

        if ($existing) {
            $existing->update($data);
            $license = $existing->fresh();
            $this->info("✓ License updated (id={$license->id})");
        } else {
            $license = License::create($data);
            $this->info("✓ License imported (id={$license->id})");
        }

        $this->newLine();
        $this->line('  Key       : ' . $license->license_key);
        $this->line('  Expires   : ' . $license->expires_at->toDateString());
        $this->line('  Status    : ' . $license->status);
        $this->line('  Machine   : ' . ($license->machine_id ?? '— not yet bound —'));

        if ($this->option('customer')) {
            $this->line('  Customer  : ' . $this->option('customer'));
        }

        $this->newLine();
        $this->comment('Next: the customer\'s upgraded Agent will show a "please re-activate" prompt.');
        $this->comment('When they click Activate with this same key, the Agent completes the new');
        $this->comment('signed-payload flow against this row and syncs normally from that point on.');

        return self::SUCCESS;
    }
}
