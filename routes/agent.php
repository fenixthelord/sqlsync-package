<?php

use Illuminate\Support\Facades\Route;
use SqlSync\LaravelSqlSync\Http\Controllers\SyncController;
use SqlSync\LaravelSqlSync\Http\Middleware\AgentAuth;

$prefix     = config('sqlsync.routes.prefix', 'sqlsync');
$middleware = config('sqlsync.routes.middleware', ['api']);
$agentPrefix = config('sqlsync.routes.agent_prefix', 'agent');

Route::prefix($prefix . '/' . $agentPrefix)
    ->middleware(array_merge($middleware, [AgentAuth::class]))
    ->name('sqlsync.agent.')
    ->group(function () {
        Route::post('sync',      [SyncController::class, 'receive'])->name('sync');
        Route::post('heartbeat', [SyncController::class, 'heartbeat'])->name('heartbeat');
        Route::get('logs',       [SyncController::class, 'logs'])->name('logs');
    });
