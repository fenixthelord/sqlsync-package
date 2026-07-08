<?php

use Illuminate\Support\Facades\Route;
use SqlSync\LaravelSqlSync\Http\Controllers\SyncController;
use SqlSync\LaravelSqlSync\Http\Controllers\Api\LicenseController;
use SqlSync\LaravelSqlSync\Http\Middleware\AgentAuth;

$prefix      = config('sqlsync.routes.prefix', 'sqlsync');
$middleware  = config('sqlsync.routes.middleware', ['api']);
$agentPrefix = config('sqlsync.routes.agent_prefix', 'agent');

Route::prefix($prefix . '/' . $agentPrefix)
    ->middleware($middleware)
    ->name('sqlsync.agent.')
    ->group(function () use ($middleware) {

        // ── Public: no HMAC required. Public key is safe to hand out ──
        Route::get('license/public-key', [LicenseController::class, 'publicKey'])
            ->name('license.public-key');

        // ── Authenticated: HMAC required ──
        Route::middleware(AgentAuth::class)->group(function () {
            Route::post('sync',      [SyncController::class, 'receive'])->name('sync');
            Route::post('heartbeat', [SyncController::class, 'heartbeat'])->name('heartbeat');
            Route::get('logs',       [SyncController::class, 'logs'])->name('logs');

            Route::post('license/activate', [LicenseController::class, 'activate'])
                ->name('license.activate');
            Route::post('license/verify', [LicenseController::class, 'verify'])
                ->name('license.verify');
        });
    });
