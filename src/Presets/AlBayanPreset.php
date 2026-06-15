<?php

namespace SqlSync\LaravelSqlSync\Presets;

use SqlSync\LaravelSqlSync\Contracts\PresetContract;

/**
 * Maps records from Al-Bayan (البيان) accounting software.
 * Schema mapping pending — update once database structure is confirmed.
 */
class AlBayanPreset implements PresetContract
{
    public function map(array $raw): array
    {
        return [
            'source_guid' => $raw['guid']      ?? $raw['id'] ?? null,
            'name'        => $raw['name']       ?? $raw['item_name'] ?? '',
            'latin_name'  => $raw['latin_name'] ?? null,
            'code'        => $raw['code']       ?? null,
            'barcode'     => $raw['barcode']    ?? null,
            'group_name'  => $raw['group']      ?? $raw['category'] ?? null,
            'unit'        => $raw['unit']       ?? null,
            'quantity'    => $raw['quantity']   ?? $raw['qty'] ?? 0,
            'is_active'   => (bool) ($raw['is_active'] ?? $raw['active'] ?? true),
            'extra_data'  => array_diff_key($raw, array_flip([
                'guid', 'id', 'name', 'item_name', 'latin_name',
                'code', 'barcode', 'group', 'category',
                'unit', 'quantity', 'qty', 'is_active', 'active',
            ])),
        ];
    }
}
