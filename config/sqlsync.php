<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agent Authentication
    |--------------------------------------------------------------------------
    | Each agent must send these headers with every request:
    |   X-Agent-ID    : unique identifier for the Windows machine
    |   X-Agent-Token : HMAC-SHA256 signature
    |   X-Timestamp   : Unix timestamp (replay protection)
    |
    */
    'agent' => [
        'secret'              => env('SQLSYNC_AGENT_SECRET'),
        'timestamp_tolerance' => 300, // seconds (5 minutes)
    ],

    /*
    |--------------------------------------------------------------------------
    | License Signing
    |--------------------------------------------------------------------------
    | RSA keypair used to sign license payloads sent to Agents. The private
    | key never leaves the server. Generate with:
    |
    |   php artisan sqlsync:generate-license-keypair
    |
    | Both keys are base64-encoded PEM so they fit in .env as single lines.
    |
    */
    'license' => [
        'private_key' => env('SQLSYNC_LICENSE_PRIVATE_KEY')
            ? base64_decode(env('SQLSYNC_LICENSE_PRIVATE_KEY'))
            : null,

        'public_key'  => env('SQLSYNC_LICENSE_PUBLIC_KEY')
            ? base64_decode(env('SQLSYNC_LICENSE_PUBLIC_KEY'))
            : null,

        // How often the Agent should re-verify online. Between checks,
        // the Agent trusts its locally-cached signed payload.
        'verify_every_days'  => (int) env('SQLSYNC_LICENSE_VERIFY_EVERY_DAYS', 7),

        // How long the Agent can run offline after the last successful
        // online verification before sync is paused.
        'offline_grace_days' => (int) env('SQLSYNC_LICENSE_OFFLINE_GRACE_DAYS', 30),

        // Trial period for freshly-installed Agents that have no license yet.
        'trial_days' => (int) env('SQLSYNC_LICENSE_TRIAL_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    */
    'multi_tenant' => env('SQLSYNC_MULTI_TENANT', false),

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'prefix'       => 'sqlsync',
        'middleware'   => ['api'],
        'agent_prefix' => 'agent',
        'api_prefix'   => 'api/v1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Settings
    |--------------------------------------------------------------------------
    */
    'sync' => [
        'log_enabled'        => env('SQLSYNC_LOG', true),
        'log_retention_days' => 30,
        'batch_size'         => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Presets
    |--------------------------------------------------------------------------
    */
    'presets' => [
        'al_ameen' => \SqlSync\LaravelSqlSync\Presets\AlAmeenPreset::class,
        'al_bayan' => \SqlSync\LaravelSqlSync\Presets\AlBayanPreset::class,
    ],

];
