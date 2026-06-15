<?php

namespace SqlSync\LaravelSqlSync\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AgentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $agentId   = $request->header('X-Agent-ID');
        $signature = $request->header('X-Agent-Token');
        $timestamp = $request->header('X-Timestamp');

        // Validate headers present
        if (! $agentId || ! $signature || ! $timestamp) {
            return response()->json(['error' => 'Missing agent authentication headers.'], 401);
        }

        // Replay attack protection
        $tolerance = config('sqlsync.agent.timestamp_tolerance', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return response()->json(['error' => 'Request timestamp expired.'], 401);
        }

        // Signature verification
        $secret   = config('sqlsync.agent.secret');
        if (! $secret) {
            return response()->json(['error' => 'SqlSync agent secret not configured.'], 500);
        }

        $payload  = $agentId . '|' . $timestamp;
        $expected = hash_hmac('sha256', $payload, $secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid agent signature.'], 401);
        }

        // Attach agent ID to request for downstream use
        $request->merge(['_agent_id' => $agentId]);

        return $next($request);
    }
}
