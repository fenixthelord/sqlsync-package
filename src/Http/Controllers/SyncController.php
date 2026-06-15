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
     * Expected payload:
     * {
     *   "preset"    : "al_ameen",
     *   "company_id": 1,           // only when multi_tenant = true
     *   "records"   : [ { ... } ]
     * }
     */
    public function receive(Request $request): JsonResponse
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
            preset:    $request->input('preset'),
            records:   $request->input('records'),
            agentId:   $request->input('_agent_id'),
            companyId: $request->input('company_id'),
        );

        return response()->json([
            'success'  => true,
            'inserted' => $result['inserted'],
            'updated'  => $result['updated'],
            'skipped'  => $result['skipped'],
        ]);
    }

    /**
     * Agent heartbeat — lets the backend track agent activity.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $this->syncService->recordHeartbeat(
            agentId:   $request->input('_agent_id'),
            companyId: $request->input('company_id'),
        );

        return response()->json(['status' => 'ok']);
    }

    /**
     * Returns sync log for this agent.
     */
    public function logs(Request $request): JsonResponse
    {
        $logs = $this->syncService->getLogsForAgent(
            agentId:   $request->input('_agent_id'),
            companyId: $request->input('company_id'),
        );

        return response()->json($logs);
    }
}
