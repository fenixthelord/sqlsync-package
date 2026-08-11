<?php

namespace SqlSync\LaravelSqlSync\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SqlSync\LaravelSqlSync\Services\AccountingSourceScope;
use SqlSync\LaravelSqlSync\Services\SyncService;

class SyncController extends Controller
{
    public function __construct(
        protected SyncService $syncService,
        protected AccountingSourceScope $sourceScope,
    ) {}

    /**
     * Receive a sync batch from the Windows Agent.
     *
     * Supports two payload formats, chosen by the "version" field:
     * v1 remains backward-compatible; v2 may carry accounting_source_uuid so
     * retries and canonical commerce truth are isolated when the configured SQL
     * database changes.
     */
    public function receive(Request $request): JsonResponse
    {
        $version = (int) $request->input('version', 1);

        return $version === 2
            ? $this->receiveV2($request)
            : $this->receiveV1($request);
    }

    private function receiveV1(Request $request): JsonResponse
    {
        $request->validate([
            'preset'    => ['required', 'string'],
            'records'   => ['required', 'array'],
            'records.*' => ['array'],
        ]);

        if (config('sqlsync.multi_tenant')) {
            $request->validate(['company_id' => ['required', 'integer']]);
        }

        $result = $this->syncService->process(
            provider:       $request->input('preset'),
            dataset:        null,
            records:        $request->input('records'),
            agentId:        (string) $request->input('_agent_id'),
            companyId:      $request->input('company_id'),
            batchIndex:     null,
            batchCount:     null,
            idempotencyKey: null,
            watermark:      null,
        );

        return response()->json([
            'success'  => true,
            'version'  => 1,
            'inserted' => $result['inserted'],
            'updated'  => $result['updated'],
            'skipped'  => $result['skipped'],
        ]);
    }

    private function receiveV2(Request $request): JsonResponse
    {
        $request->validate([
            'provider'                => ['required', 'string'],
            'dataset'                 => ['required', 'string'],
            'accounting_source_uuid'  => ['nullable', 'uuid'],
            'batch'                   => ['required', 'array'],
            'batch.index'             => ['required', 'integer', 'min:0'],
            // Streaming Agents don't know the total batch count upfront —
            // they push each batchSize-sized buffer as it fills up out of
            // a SqlDataReader. Only the final flush of the tail buffer
            // carries a count, and even then it's advisory.
            'batch.count'             => ['nullable', 'integer', 'min:1'],
            'batch.idempotency_key'   => ['required', 'string', 'max:64'],
            'watermark'               => ['nullable', 'date'],
            'records'                 => ['required', 'array'],
            'records.*'               => ['array'],
        ]);

        if (config('sqlsync.multi_tenant')) {
            $request->validate(['company_id' => ['required', 'integer']]);
        }

        $provider = (string) $request->input('provider');
        $companyId = $request->filled('company_id')
            ? (int) $request->input('company_id')
            : null;
        $accountingSourceUuid = $request->filled('accounting_source_uuid')
            ? strtolower(trim((string) $request->input('accounting_source_uuid')))
            : null;

        if ($accountingSourceUuid !== null) {
            // This runs before SyncService's replay lookup, so the first core
            // batch after a source switch clears stale canonical accounting
            // truth even if that batch later turns out to be a transport retry.
            $this->sourceScope->ensure(
                $provider,
                $accountingSourceUuid,
                $companyId,
            );
        }

        $wireIdempotencyKey = (string) $request->input('batch.idempotency_key');
        $effectiveIdempotencyKey = $accountingSourceUuid === null
            ? $wireIdempotencyKey
            : hash('sha256', $accountingSourceUuid . '|' . $wireIdempotencyKey);

        $result = $this->syncService->process(
            provider:       $provider,
            dataset:        $request->input('dataset'),
            records:        $request->input('records'),
            agentId:        (string) $request->input('_agent_id'),
            companyId:      $companyId,
            batchIndex:     $request->input('batch.index'),
            batchCount:     $request->input('batch.count'),
            idempotencyKey: $effectiveIdempotencyKey,
            watermark:      $request->input('watermark'),
        );

        return response()->json([
            'success'         => true,
            'version'         => 2,
            'inserted'        => $result['inserted'],
            'updated'         => $result['updated'],
            'skipped'         => $result['skipped'],
            'replay'          => $result['replay'] ?? false,
            'idempotency_key' => $wireIdempotencyKey,
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $request->validate([
            'accounting_source_uuid' => ['nullable', 'uuid'],
        ]);

        if (config('sqlsync.multi_tenant')) {
            $request->validate(['company_id' => ['required', 'integer']]);
        }

        $companyId = $request->filled('company_id')
            ? (int) $request->input('company_id')
            : null;

        if ($request->filled('accounting_source_uuid')) {
            // Heartbeat is the first network call in a normal Agent cycle. If a
            // source binding already exists, observing a new UUID here removes
            // stale canonical pricing before the core extraction even begins.
            $this->sourceScope->observeSource(
                strtolower(trim((string) $request->input('accounting_source_uuid'))),
                $companyId,
            );
        }

        $this->syncService->recordHeartbeat(
            agentId:   (string) $request->input('_agent_id'),
            companyId: $companyId,
        );

        return response()->json(['status' => 'ok']);
    }

    public function logs(Request $request): JsonResponse
    {
        $logs = $this->syncService->getLogsForAgent(
            agentId:   (string) $request->input('_agent_id'),
            companyId: $request->input('company_id'),
        );

        return response()->json($logs);
    }
}
