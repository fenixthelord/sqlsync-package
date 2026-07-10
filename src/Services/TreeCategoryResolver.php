<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Services;

use SqlSync\LaravelSqlSync\Models\CategoryNode;

/**
 * Resolves a product's raw hierarchical tree-path value (e.g. Al-Bayan's
 * TreeNum: '117185') to a human-readable category name, by walking up
 * the tree — stripping the trailing 3-digit segment at a time — until a
 * synced CategoryNode with a matching tree_num is found, or the path is
 * exhausted.
 *
 * Why 3 digits at a time: verified empirically against a live Al-Bayan
 * install (Saati Pharmacy) — category-node tree_num values are 3-digit
 * segments ('100', '101', ... '221'), and product tree_num values are
 * that 3-digit parent path followed by a 3-digit child suffix ('117' +
 * '185' = '117185'). Not every product resolves cleanly — some items
 * were simply never filed into the tree by store staff, which shows up
 * as no match at any level; that's a genuine data-completeness gap on
 * the accounting side, not a resolution bug, and callers should treat
 * a null result as "leave uncategorized" rather than retry differently.
 *
 * The walk also tolerates deeper trees (more than 2 levels) — a 9-digit
 * path tries the 6-digit prefix first, then the 3-digit prefix, before
 * giving up. Nothing about this class is Al-Bayan-specific beyond the
 * 3-digit segment width; if a future preset uses a different segment
 * size, this can take it as a constructor parameter rather than a new
 * class.
 */
class TreeCategoryResolver
{
    private const SEGMENT_WIDTH = 3;

    public function resolveName(string $treeNum, ?int $companyId): ?string
    {
        $treeNum = trim($treeNum);

        if ($treeNum === '' || ! ctype_digit($treeNum)) {
            return null;
        }

        while (strlen($treeNum) > 0) {
            $node = CategoryNode::where('tree_num', $treeNum)
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->first();

            if ($node) {
                return $node->name;
            }

            if (strlen($treeNum) <= self::SEGMENT_WIDTH) {
                break;
            }

            $treeNum = substr($treeNum, 0, -self::SEGMENT_WIDTH);
        }

        return null;
    }
}
