<?php

namespace SqlSync\LaravelSqlSync\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SqlSync\LaravelSqlSync\Services\LicenseService;

class LicenseController extends Controller
{
    public function __construct(protected LicenseService $service) {}

    /**
     * Return the server's RSA public key so the Agent can pin it.
     * No auth required — public keys are, by definition, public.
     */
    public function publicKey(): JsonResponse
    {
        return response()->json([
            'public_key' => $this->service->getPublicKey(),
            'algorithm'  => 'RS256',
        ]);
    }

    /**
     * First-time activation. The Agent authenticates via HMAC (AgentAuth
     * middleware) and posts its license_key + machine_id.
     */
    public function activate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'license_key' => ['required', 'string', 'max:32'],
            'machine_id'  => ['required', 'string', 'max:64'],
            'company_id'  => ['nullable', 'integer'],
        ]);

        $result = $this->service->activate(
            licenseKey: $validated['license_key'],
            machineId:  $validated['machine_id'],
            agentId:    (string) $request->input('_agent_id'),
            companyId:  $validated['company_id'] ?? null,
        );

        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json($result['payload']);
    }

    /**
     * Periodic re-verification. Returns a fresh signed payload with
     * a new issued_at timestamp so the Agent can extend its offline window.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'license_key' => ['required', 'string', 'max:32'],
            'machine_id'  => ['required', 'string', 'max:64'],
        ]);

        $result = $this->service->verify(
            licenseKey: $validated['license_key'],
            machineId:  $validated['machine_id'],
        );

        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json($result['payload']);
    }
}
