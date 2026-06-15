<?php

namespace SqlSync\LaravelSqlSync\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use SqlSync\LaravelSqlSync\Models\SyncedRecord;
use SqlSync\LaravelSqlSync\Models\SyncAgent;

class RecordController extends Controller
{
    /**
     * List synced records — filterable by preset, search, pagination.
     *
     * GET /sqlsync/api/v1/records?preset=al_ameen&search=bread&per_page=20
     */
    public function index(Request $request): JsonResponse
    {
        $query = SyncedRecord::query();

        // Multi-tenant isolation
        if (config('sqlsync.multi_tenant') && $request->has('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        if ($request->filled('preset')) {
            $query->where('preset', $request->input('preset'));
        }

        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('barcode', 'like', "%{$term}%")
                  ->orWhere('code', 'like', "%{$term}%");
            });
        }

        $records = $query
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json($records);
    }

    /**
     * Get a single synced record by its source GUID.
     *
     * GET /sqlsync/api/v1/records/{guid}
     */
    public function show(string $guid): JsonResponse
    {
        $record = SyncedRecord::where('source_guid', $guid)->firstOrFail();

        return response()->json($record);
    }

    /**
     * Dashboard stats for admin use.
     *
     * GET /sqlsync/api/v1/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $query = SyncedRecord::query();

        if (config('sqlsync.multi_tenant') && $request->has('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        return response()->json([
            'total_records' => (clone $query)->count(),
            'presets'       => (clone $query)->selectRaw('preset, count(*) as count')
                                             ->groupBy('preset')
                                             ->pluck('count', 'preset'),
            'last_sync'     => (clone $query)->max('synced_at'),
            'agents_online' => SyncAgent::where('last_heartbeat', '>=', now()->subMinutes(5))->count(),
        ]);
    }
}
