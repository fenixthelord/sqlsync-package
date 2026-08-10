<?php

namespace SqlSync\LaravelSqlSync\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SqlSync\LaravelSqlSync\Models\SyncLog;
use SqlSync\LaravelSqlSync\Services\AccountingCommerceStore;

class AccountingCommerceSyncController extends Controller
{
    public function __construct(protected AccountingCommerceStore $commerceStore) {}

    public function __invoke(Request $request): JsonResponse
    {
        $dataset = $this->datasetForRequest($request);
        $data = $request->validate($this->validationRules($dataset));

        if (config('sqlsync.multi_tenant')) {
            $request->validate(['company_id' => ['required', 'integer']]);
        }

        $agentId = (string) $request->input('_agent_id');
        $companyId = $request->filled('company_id')
            ? (int) $request->input('company_id')
            : null;
        $provider = strtolower(trim((string) $data['provider']));
        $idempotencyKey = (string) data_get($data, 'batch.idempotency_key');

        $existing = SyncLog::query()
            ->where('agent_id', $agentId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null && in_array($existing->status, ['success', 'warning'], true)) {
            return response()->json([
                'success' => true,
                'version' => 2,
                'dataset' => $existing->dataset,
                'inserted' => (int) $existing->inserted,
                'updated' => (int) $existing->updated,
                'skipped' => (int) $existing->skipped,
                'replay' => true,
                'idempotency_key' => $idempotencyKey,
            ]);
        }

        $result = match ($dataset) {
            'accounting_currencies' => $this->commerceStore->syncCurrencies(
                $provider,
                $data['records'],
                $companyId,
            ),
            'accounting_product_currency_bindings' => $this->commerceStore
                ->syncProductCurrencyBindings($provider, $data['records'], $companyId),
            'accounting_price_offers' => $this->commerceStore->syncPriceOffers(
                $provider,
                $data['records'],
                $companyId,
            ),
        };

        $accepted = (int) $result['created'] + (int) $result['updated'];
        $rejected = (int) $result['rejected'];
        $status = $rejected === 0
            ? 'success'
            : ($accepted > 0 ? 'warning' : 'error');

        $logValues = [
            'agent_id' => $agentId,
            'company_id' => $companyId,
            'preset' => $provider,
            'dataset' => $dataset,
            'batch_index' => data_get($data, 'batch.index'),
            'batch_count' => data_get($data, 'batch.count'),
            'idempotency_key' => $idempotencyKey,
            'high_watermark' => $data['watermark'] ?? null,
            'inserted' => (int) $result['created'],
            'updated' => (int) $result['updated'],
            'skipped' => $rejected,
            'status' => $status,
            'message' => $result['errors'] === []
                ? null
                : json_encode($result['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'synced_at' => now(),
        ];

        if ($existing !== null) {
            $existing->fill($logValues)->save();
        } else {
            SyncLog::create($logValues);
        }

        $payload = [
            'success' => $accepted > 0 || $rejected === 0,
            'version' => 2,
            'dataset' => $dataset,
            'received' => (int) $result['received'],
            'inserted' => (int) $result['created'],
            'updated' => (int) $result['updated'],
            'skipped' => $rejected,
            'errors' => $result['errors'],
            'replay' => false,
            'idempotency_key' => $idempotencyKey,
        ];

        if ($accepted === 0 && $rejected > 0) {
            return response()->json($payload, 422);
        }

        return response()->json($payload);
    }

    /** @return array<string, array<int, mixed>> */
    private function validationRules(string $dataset): array
    {
        $common = [
            'version' => ['nullable', 'integer', 'in:2'],
            'provider' => ['required', 'string', 'in:al_ameen,al_bayan'],
            'company_id' => ['nullable', 'integer'],
            'batch' => ['required', 'array'],
            'batch.index' => ['required', 'integer', 'min:0'],
            'batch.count' => ['nullable', 'integer', 'min:1'],
            'batch.idempotency_key' => ['required', 'string', 'max:64'],
            'watermark' => ['nullable', 'date'],
            'records' => ['required', 'array', 'min:1', 'max:1000'],
            'records.*' => ['array'],
            'records.*.is_deleted' => ['nullable', 'boolean'],
            'records.*.provider_metadata' => ['nullable', 'array'],
        ];

        return $common + match ($dataset) {
            'accounting_currencies' => [
                'records.*.provider_source_id' => ['required', 'string', 'max:191'],
                'records.*.name' => ['nullable', 'string', 'max:255'],
                'records.*.latin_name' => ['nullable', 'string', 'max:255'],
                'records.*.code' => ['nullable', 'string', 'max:255'],
                'records.*.iso_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
                'records.*.is_base' => ['nullable', 'boolean'],
                'records.*.rate_to_base' => ['nullable', 'numeric'],
            ],
            'accounting_product_currency_bindings' => [
                'records.*.product_source_id' => ['required', 'string', 'max:191'],
                'records.*.currency_source_id' => ['nullable', 'string', 'max:191'],
            ],
            'accounting_price_offers' => [
                'records.*.product_source_id' => ['required', 'string', 'max:191'],
                'records.*.price_key' => ['required', 'string', 'max:80'],
                'records.*.label' => ['nullable', 'string', 'max:255'],
                'records.*.amount' => ['nullable', 'numeric', 'min:0'],
                'records.*.currency_source_id' => ['nullable', 'string', 'max:191'],
                'records.*.unit' => ['nullable', 'string', 'max:255'],
            ],
        };
    }

    private function datasetForRequest(Request $request): string
    {
        return match ($request->route()?->getName()) {
            'sqlsync.agent.sync.accounting.product-currency-bindings' => 'accounting_product_currency_bindings',
            'sqlsync.agent.sync.accounting.price-offers' => 'accounting_price_offers',
            default => 'accounting_currencies',
        };
    }
}
