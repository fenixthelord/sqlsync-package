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
        'secret'             => env('SQLSYNC_AGENT_SECRET', null),
        'timestamp_tolerance' => 300, // seconds (5 minutes)
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    | Set to true if your application serves multiple companies.
    | When enabled, every sync request must include a valid company_id.
    |
    */
    'multi_tenant' => env('SQLSYNC_MULTI_TENANT', false),

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'prefix'     => 'sqlsync',
        'middleware' => ['api'],
        'agent_prefix' => 'agent',
        'api_prefix'   => 'api/v1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Settings
    |--------------------------------------------------------------------------
    */
    'sync' => [
        'log_enabled'     => env('SQLSYNC_LOG', true),
        'log_retention_days' => 30,
        'batch_size'      => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Presets
    |--------------------------------------------------------------------------
    | These map to the preset names sent by the Windows Agent.
    |
    */
    'presets' => [
        'al_ameen' => \SqlSync\LaravelSqlSync\Presets\AlAmeenPreset::class,
        'al_bayan' => \SqlSync\LaravelSqlSync\Presets\AlBayanPreset::class,
    ],

];
