<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SqlSync\LaravelSqlSync\SqlSyncServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SqlSyncServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('sqlsync.agent.secret', 'test-agent-secret');
        $app['config']->set('sqlsync.multi_tenant', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }

    /** @return array<string, string> */
    protected function agentHeaders(string $agentId = 'test-agent'): array
    {
        $timestamp = (string) time();

        return [
            'X-Agent-ID' => $agentId,
            'X-Timestamp' => $timestamp,
            'X-Agent-Token' => hash_hmac(
                'sha256',
                $agentId . '|' . $timestamp,
                'test-agent-secret',
            ),
        ];
    }
}
