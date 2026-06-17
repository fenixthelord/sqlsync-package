<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SqlSync\LaravelSqlSync\Models\FieldMapping;

class MappingController extends Controller
{
    /**
     * GET /sqlsync/api/v1/mappings?preset=al_ameen&company_id=1
     *
     * Returns field mappings for a preset.
     * Falls back to defaults if no custom mappings exist.
     */
    public function index(Request $request): JsonResponse
    {
        $preset    = $request->input('preset', 'al_ameen');
        $companyId = $request->integer('company_id') ?: null;

        $mappings = FieldMapping::where('preset', $preset)
            ->where(fn($q) => $q
                ->whereNull('company_id')
                ->orWhere('company_id', $companyId)
            )
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->get();

        // Fall back to defaults if nothing configured yet
        if ($mappings->isEmpty()) {
            $mappings = collect(FieldMapping::defaultsForPreset($preset))
                ->map(fn($m) => array_merge($m, ['preset' => $preset, 'company_id' => null]));
        }

        return response()->json([
            'preset'   => $preset,
            'mappings' => $mappings->values(),
        ]);
    }
}
