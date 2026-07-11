<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Services;

use SqlSync\LaravelSqlSync\Models\SyncedRecord;

/**
 * Reports SyncedRecord rows that look like they may have been deleted
 * at the source (Al-Bayan / Al-Ameen / whatever accounting software) —
 * for human review ONLY. Never deletes or deactivates anything
 * automatically; per explicit decision, deletion is too dangerous to
 * automate (a deleted-looking item could be an order line, a bundle
 * component, anything already in use on the live site).
 *
 * ── Why this can ONLY be based on a genuine full resync ──────────────
 *
 * The regular sync cycle is INCREMENTAL — it only fetches rows whose
 * UDate changed since the last watermark (see Worker.cs /
 * PresetQuery.SinceColumn). An item that's simply unchanged for months
 * never appears in an incremental query result AT ALL — not because it
 * was deleted, but because nothing about it changed. Its SyncedRecord's
 * synced_at timestamp is consequently just as "stale" as a genuinely
 * deleted item's would be. There is NO way to distinguish "quietly
 * unchanged for a long time" from "deleted" using incremental sync data
 * alone — both look identical from the receiving end.
 *
 * The only reliable signal is a genuine FULL scan: every currently-
 * existing item gets touched (its synced_at refreshed), so anything
 * NOT touched during that specific full scan is a genuine candidate —
 * it exists in our records but the source didn't send it even during
 * a scan that should have included every current item.
 *
 * Practical implication: this report is only trustworthy relative to
 * the most recent Force Full Resync. Running it after only incremental
 * cycles will flag a large number of false positives (everything
 * unchanged since before that period). The report surfaces the
 * timestamp of the most recent apparent full sync so the admin can
 * judge freshness themselves, and recommends running Force Full Resync
 * before trusting the results.
 */
class StaleRecordsReportService
{
    /**
     * @return array{
     *   candidates: \Illuminate\Support\Collection,
     *   last_full_sync_estimate: ?\Carbon\Carbon,
     *   reliable: bool,
     * }
     */
    public function report(?int $companyId = null, int $staleDays = 3): array
    {
        $query = SyncedRecord::query()
            ->whereNotNull('product_id') // only items that actually became products matter for this report
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId));

        // Best-effort estimate of "when did the last full sync happen":
        // the most recent synced_at across ALL records is a reasonable
        // proxy — a full resync touches everything at roughly the same
        // moment, producing a dense cluster of timestamps at that time.
        $lastFullSyncEstimate = (clone $query)->max('synced_at');

        $candidates = (clone $query)
            ->where('synced_at', '<', now()->subDays($staleDays))
            ->orderBy('synced_at')
            ->get(['id', 'name', 'barcode', 'code', 'product_id', 'synced_at']);

        // "Reliable" is a soft heuristic, not a guarantee: if the most
        // recent record touch was itself more than $staleDays ago, we
        // have no recent full-scan reference point at all, and EVERY
        // record in the table would trivially qualify as "stale" — that's
        // a sign no full resync has run recently, not a sign everything
        // was deleted. Surface that distinction explicitly rather than
        // let the admin misread a large candidate list as "delete all
        // of this".
        $reliable = $lastFullSyncEstimate !== null
            && now()->diffInDays($lastFullSyncEstimate) < $staleDays;

        return [
            'candidates' => $candidates,
            'last_full_sync_estimate' => $lastFullSyncEstimate,
            'reliable' => $reliable,
        ];
    }
}
