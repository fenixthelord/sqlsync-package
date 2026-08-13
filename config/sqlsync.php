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
    | Accounting Stock Bridge
    |--------------------------------------------------------------------------
    | Optional adapter for host applications whose sellable stock lives in a
    | related batch/inventory model rather than directly on the Product row.
    | Disabled by default: enabling it is an explicit host-app integration.
    |
    | The adapter maintains exactly one synthetic accounting batch per synced
    | product. The accounting quantity remains authoritative, while quantities
    | already reserved by the web shop are protected from being re-sold:
    |
    |   available = max(0, accounting_quantity - reserved)
    |
    | No fake expiry date is generated. Host applications that require an
    | expiry column must allow NULL for this synthetic accounting batch and
    | treat NULL as "expiry not supplied by the accounting source".
    */
    'stock_bridge' => [
        'enabled' => env('SQLSYNC_STOCK_BRIDGE_ENABLED', false),
        'batch_model' => env('SQLSYNC_STOCK_BATCH_MODEL'),
        'movement_model' => env('SQLSYNC_STOCK_MOVEMENT_MODEL'),

        'product_foreign_key' => 'product_id',
        'batch_number_column' => 'batch_number',
        'batch_number_prefix' => 'SQLSYNC-ACCOUNTING-',
        'quantity_received_column' => 'quantity_received',
        'quantity_available_column' => 'quantity_available',
        'quantity_reserved_column' => 'quantity_reserved',
        'quantity_damaged_column' => 'quantity_damaged',
        'expires_at_column' => 'expires_at',
        'status_column' => 'status',
        'available_status' => 'available',
        'received_at_column' => 'received_at',

        'movement' => [
            'product_foreign_key' => 'product_id',
            'batch_foreign_key' => 'product_batch_id',
            'type_column' => 'type',
            'type' => 'accounting_reconciliation',
            'quantity_column' => 'quantity',
            'quantity_before_column' => 'quantity_before',
            'quantity_after_column' => 'quantity_after',
            'reference_type_column' => 'reference_type',
            'reference_id_column' => 'reference_id',
            'note_column' => 'note',
        ],
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
