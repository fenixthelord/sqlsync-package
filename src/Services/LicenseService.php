<?php

namespace SqlSync\LaravelSqlSync\Services;

use Carbon\Carbon;
use SqlSync\LaravelSqlSync\Models\License;

/**
 * Server-side license operations.
 *
 * Trust model: the server holds an RSA private key and signs a payload
 * containing (license_key, machine_id, agent_id, company_id, expires_at,
 * issued_at). The Agent stores this signed payload locally and verifies
 * it on every startup with the pinned public key.
 *
 * The server NEVER hands out the private key. The Agent NEVER trusts a
 * license file that isn't signed by the pinned key.
 */
class LicenseService
{
    /**
     * Activate a license key against a specific machine.
     *
     * First activation: binds machine_id to the license.
     * Re-activation on same machine: returns fresh signature.
     * Re-activation on different machine: rejected (machine_locked).
     *
     * @return array{ok: bool, error?: string, payload?: array}
     */
    public function activate(string $licenseKey, string $machineId, string $agentId, ?int $companyId): array
    {
        $license = License::where('license_key', $licenseKey)->first();

        if (! $license) {
            return ['ok' => false, 'error' => 'license_not_found'];
        }

        if (! $license->isActive()) {
            return ['ok' => false, 'error' => 'license_' . $license->status]; // suspended/revoked
        }

        if ($license->isExpired()) {
            return ['ok' => false, 'error' => 'license_expired'];
        }

        // Bind on first activation
        if ($license->machine_id === null) {
            $license->machine_id  = $machineId;
            $license->agent_id    = $agentId;
            $license->company_id  = $companyId;
            $license->activated_at = now();
        } elseif (! $license->isBoundToMachine($machineId)) {
            return ['ok' => false, 'error' => 'machine_locked'];
        }

        $license->last_verified_at = now();
        $license->save();

        return ['ok' => true, 'payload' => $this->buildSignedPayload($license)];
    }

    /**
     * Periodic online verification. Called by the Agent every N days.
     * If offline, the Agent falls back to verifying its last stored payload.
     */
    public function verify(string $licenseKey, string $machineId): array
    {
        $license = License::where('license_key', $licenseKey)->first();

        if (! $license || ! $license->isActive() || ! $license->isBoundToMachine($machineId)) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        if ($license->isExpired()) {
            return ['ok' => false, 'error' => 'license_expired'];
        }

        $license->last_verified_at = now();
        $license->save();

        return ['ok' => true, 'payload' => $this->buildSignedPayload($license)];
    }

    /**
     * Returns the server's public key so the Agent can pin it on first activation.
     * Public keys are safe to expose — they only verify signatures, they don't create them.
     */
    public function getPublicKey(): string
    {
        $pem = config('sqlsync.license.public_key');

        if (! $pem) {
            throw new \RuntimeException(
                'SqlSync license public key not configured. '
                . 'Run: php artisan sqlsync:generate-license-keypair'
            );
        }

        return $pem;
    }

    /**
     * Build the signed payload the Agent stores locally and verifies on every boot.
     *
     * We return the canonical body as a base64 blob rather than a re-serialisable
     * object so the Agent verifies against EXACTLY the bytes we signed — this
     * removes any risk of PHP/.NET JSON encoding differences (whitespace,
     * escape rules, date-format drift) breaking signature verification.
     */
    private function buildSignedPayload(License $license): array
    {
        $verifyEveryDays = (int) config('sqlsync.license.verify_every_days', 7);
        $offlineGraceDays = (int) config('sqlsync.license.offline_grace_days', 30);

        $body = [
            'license_key'        => $license->license_key,
            'machine_id'         => $license->machine_id,
            'agent_id'           => $license->agent_id,
            'company_id'         => $license->company_id,
            'expires_at'         => $license->expires_at->toIso8601String(),
            'issued_at'          => Carbon::now()->toIso8601String(),
            'verify_every_days'  => $verifyEveryDays,
            'offline_grace_days' => $offlineGraceDays,
        ];

        ksort($body);
        $canonical = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $signature = $this->sign($canonical);

        return [
            // Base64-encoded canonical bytes — the Agent decodes and verifies
            // signature against THIS blob before parsing anything out of it.
            'payload'   => base64_encode($canonical),
            'signature' => base64_encode($signature),
            'algorithm' => 'RS256',
        ];
    }

    private function sign(string $data): string
    {
        $privateKey = config('sqlsync.license.private_key');

        if (! $privateKey) {
            throw new \RuntimeException(
                'SqlSync license private key not configured. '
                . 'Run: php artisan sqlsync:generate-license-keypair'
            );
        }

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new \RuntimeException('Invalid SqlSync license private key.');
        }

        $signature = '';
        $ok = openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);

        if (! $ok) {
            throw new \RuntimeException('Failed to sign license payload.');
        }

        return $signature;
    }
}
