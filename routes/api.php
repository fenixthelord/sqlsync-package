<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SqlSync\LaravelSqlSync\Http\Controllers\Api\MappingController;
use SqlSync\LaravelSqlSync\Http\Controllers\Api\RecordController;

$prefix    = config('sqlsync.routes.prefix', 'sqlsync');
$middleware = config('sqlsync.routes.middleware', ['api']);
$apiPrefix = config('sqlsync.routes.api_prefix', 'api/v1');

Route::prefix($prefix . '/' . $apiPrefix)
    ->middleware($middleware)
    ->name('sqlsync.api.')
    ->group(function () {
        Route::get('records',        [RecordController::class, 'index'])->name('records.index');
        Route::get('records/{guid}', [RecordController::class, 'show'])->name('records.show');
        Route::get('stats',          [RecordController::class, 'stats'])->name('stats');
        Route::get('mappings',       [MappingController::class, 'index'])->name('mappings.index');
    });
