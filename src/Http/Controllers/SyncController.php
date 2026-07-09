<?php

namespace SqlSync\LaravelSqlSync\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use SqlSync\LaravelSqlSync\Services\SyncService;

class SyncController extends Controller
{
    public function __construct(protected SyncService $syncService) {}

    /**
     * Receive a sync batch from the Windows Agent.
     *
     * Supports two payload formats, chosen by the "version" field:
     *
     * v1 (legacy — Agents pre-Batch B):
     *   {
     *     "preset"    : "al_ameen",
     *     "company_id": 1,
     *     "records"   : [ { ... } ]
     *   }
     *
     * v2 (current):
     *   {
     *     "version"   : 2,
     *     "provider"  : "al_ameen",
     *     "dataset"   : "materials",
     *     "company_id": 1,
     *     "batch"     : {
     *       "index"           : 0,
     *       "count"           : 1,
     *       "idempotency_key" : "…"
     *     },
     *     "watermark" : "2026-07-01T14:23:15Z",
     *     "records"   : [ { ... } ]
     *   }
     *
     * The old Agent binaries in the field can keep sending v1 while
     * upgraded ones send v2 — no coordinated deploy needed.
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
            'provider'              => ['required', 'string'],
            'dataset'               => ['required', 'string'],
            'batch'                 => ['required', 'array'],
            'batch.index'           => ['required', 'integer', 'min:0'],
            // Streaming Agents don't know the total batch count upfront —
            // they push each batchSize-sized buffer as it fills up out of
            // a SqlDataReader. Only the final flush of the tail buffer
            // carries a count, and even then it's advisory.
            'batch.count'           => ['nullable', 'integer', 'min:1'],
            'batch.idempotency_key' => ['required', 'string', 'max:64'],
            'watermark'             => ['nullable', 'date'],
            'records'               => ['required', 'array'],
            'records.*'             => ['array'],
        ]);

        if (config('sqlsync.multi_tenant')) {
            $request->validate(['company_id' => ['required', 'integer']]);
        }

        $result = $this->syncService->process(
            provider:       $request->input('provider'),
            dataset:        $request->input('dataset'),
            records:        $request->input('records'),
            agentId:        (string) $request->input('_agent_id'),
            companyId:      $request->input('company_id'),
            batchIndex:     $request->input('batch.index'),
            batchCount:     $request->input('batch.count'),
            idempotencyKey: $request->input('batch.idempotency_key'),
            watermark:      $request->input('watermark'),
        );

        return response()->json([
            'success'         => true,
            'version'         => 2,
            'inserted'        => $result['inserted'],
            'updated'         => $result['updated'],
            'skipped'         => $result['skipped'],
            'replay'          => $result['replay'] ?? false,
            'idempotency_key' => $request->input('batch.idempotency_key'),
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $this->syncService->recordHeartbeat(
            agentId:   (string) $request->input('_agent_id'),
            companyId: $request->input('company_id'),
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
