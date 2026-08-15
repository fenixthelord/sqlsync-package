<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Tests\Feature;

use Illuminate\Support\Facades\DB;
use SqlSync\LaravelSqlSync\Models\BridgeLog;
use SqlSync\LaravelSqlSync\Tests\TestCase;

class LogRetentionTest extends TestCase
{
    public function test_successful_bridge_rows_are_not_stored_by_default(): void
    {
        config()->set('sqlsync.bridge.log_successful_actions', false);

        BridgeLog::create([
            'record_name' => 'Product A',
            'action' => 'updated',
        ]);

        BridgeLog::create([
            'record_name' => 'Product B',
            'action' => 'skipped',
            'reason' => 'db_error',
            'detail' => 'Example diagnostic failure',
        ]);

        $this->assertDatabaseCount('sqlsync_bridge_logs', 1);
        $this->assertDatabaseHas('sqlsync_bridge_logs', [
            'record_name' => 'Product B',
            'action' => 'skipped',
            'reason' => 'db_error',
        ]);
    }

    public function test_successful_bridge_rows_can_be_explicitly_enabled(): void
    {
        config()->set('sqlsync.bridge.log_successful_actions', true);

        BridgeLog::create([
            'record_name' => 'Product A',
            'action' => 'updated',
        ]);

        $this->assertDatabaseHas('sqlsync_bridge_logs', [
            'record_name' => 'Product A',
            'action' => 'updated',
        ]);
    }

    public function test_prune_command_removes_success_noise_and_expired_logs(): void
    {
        config()->set('sqlsync.bridge.log_successful_actions', false);
        config()->set('sqlsync.bridge.log_retention_days', 14);
        config()->set('sqlsync.sync.log_retention_days', 30);

        $now = now();

        DB::table('sqlsync_bridge_logs')->insert([
            [
                'record_name' => 'Recent success',
                'action' => 'updated',
                'created_at' => $now->copy()->subDay(),
                'updated_at' => $now->copy()->subDay(),
            ],
            [
                'record_name' => 'Expired diagnostic',
                'action' => 'skipped',
                'reason' => 'db_error',
                'created_at' => $now->copy()->subDays(15),
                'updated_at' => $now->copy()->subDays(15),
            ],
            [
                'record_name' => 'Recent diagnostic',
                'action' => 'skipped',
                'reason' => 'db_error',
                'created_at' => $now->copy(),
                'updated_at' => $now->copy(),
            ],
        ]);

        DB::table('sqlsync_logs')->insert([
            [
                'agent_id' => 'agent-old',
                'preset' => 'al_bayan',
                'inserted' => 0,
                'updated' => 1,
                'skipped' => 0,
                'status' => 'success',
                'synced_at' => $now->copy()->subDays(31),
            ],
            [
                'agent_id' => 'agent-new',
                'preset' => 'al_bayan',
                'inserted' => 0,
                'updated' => 1,
                'skipped' => 0,
                'status' => 'success',
                'synced_at' => $now->copy(),
            ],
        ]);

        $this->artisan('sqlsync:prune-logs')
            ->assertSuccessful();

        $this->assertDatabaseMissing('sqlsync_bridge_logs', [
            'record_name' => 'Recent success',
        ]);
        $this->assertDatabaseMissing('sqlsync_bridge_logs', [
            'record_name' => 'Expired diagnostic',
        ]);
        $this->assertDatabaseHas('sqlsync_bridge_logs', [
            'record_name' => 'Recent diagnostic',
        ]);

        $this->assertDatabaseMissing('sqlsync_logs', [
            'agent_id' => 'agent-old',
        ]);
        $this->assertDatabaseHas('sqlsync_logs', [
            'agent_id' => 'agent-new',
        ]);
    }
}
