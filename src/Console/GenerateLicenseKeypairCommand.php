<?php

namespace SqlSync\LaravelSqlSync\Console;

use Illuminate\Console\Command;

class GenerateLicenseKeypairCommand extends Command
{
    protected $signature = 'sqlsync:generate-license-keypair
                            {--force : Overwrite existing keys in .env}';

    protected $description = 'Generate an RSA keypair for signing SqlSync license payloads';

    public function handle(): int
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->error('.env file not found at ' . $envPath);
            return self::FAILURE;
        }

        $env = file_get_contents($envPath);
        $exists = str_contains($env, 'SQLSYNC_LICENSE_PRIVATE_KEY=');

        if ($exists && ! $this->option('force')) {
            $this->error('License keys already exist in .env. Use --force to overwrite.');
            $this->warn('WARNING: Overwriting will invalidate every already-activated Agent.');
            return self::FAILURE;
        }

        $this->info('Generating 2048-bit RSA keypair...');

        $config = [
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);
        if ($res === false) {
            $this->error('openssl_pkey_new() failed: ' . openssl_error_string());
            return self::FAILURE;
        }

        openssl_pkey_export($res, $privateKey);
        $publicKey = openssl_pkey_get_details($res)['key'];

        // Store as base64 in .env — multi-line PEM breaks .env parsers
        $privateB64 = base64_encode($privateKey);
        $publicB64  = base64_encode($publicKey);

        $lines = [
            '',
            '# SqlSync license signing keys — DO NOT SHARE THE PRIVATE KEY',
            '# Regenerate with: php artisan sqlsync:generate-license-keypair --force',
            'SQLSYNC_LICENSE_PRIVATE_KEY="' . $privateB64 . '"',
            'SQLSYNC_LICENSE_PUBLIC_KEY="' . $publicB64 . '"',
        ];

        if ($exists) {
            // Strip old entries
            $env = preg_replace(
                '/^SQLSYNC_LICENSE_(PRIVATE|PUBLIC)_KEY=.*$/m',
                '',
                $env
            );
            $env = preg_replace('/\n{3,}/', "\n\n", $env);
        }

        file_put_contents($envPath, rtrim($env) . "\n" . implode("\n", $lines) . "\n");

        $this->info('✓ Keypair written to .env');
        $this->newLine();
        $this->line('Private key: SQLSYNC_LICENSE_PRIVATE_KEY (keep secret, never expose)');
        $this->line('Public key : SQLSYNC_LICENSE_PUBLIC_KEY  (safe to distribute)');
        $this->newLine();
        $this->warn('Run: php artisan config:clear');

        return self::SUCCESS;
    }
}
