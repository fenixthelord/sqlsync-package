<?php

namespace SqlSync\LaravelSqlSync\Presets;

use SqlSync\LaravelSqlSync\Contracts\PresetContract;

/**
 * Maps records from Al-Bayan (البيان) accounting software.
 *
 * Al-Bayan has no fixed "retail price" column — businesses configure what
 * Price1..Price35 mean inside Al-Bayan itself, and that can differ between
 * installations. So every price column is passed through as-is into
 * extra_data; each install then picks the right one per company from
 * Filament -> SqlSync -> Product Bridge -> Field Mapping, exactly like any
 * other extra_data field.
 *
 * NOTE: 'group_name' here is currently just Al-Bayan's raw `Class` number
 * cast to text (see AlBayanPreset.cs) — Class turned out to look more like
 * a brand/supplier grouping than a product category when we inspected a
 * real database, and there was no lookup table to confirm its real meaning.
 * Automatic category resolution for Al-Bayan is intentionally deferred
 * until this is confirmed against the actual Al-Bayan software UI.
 */
class AlBayanPreset implements PresetContract
{
    public function map(array $raw): array
    {
        return [
            'source_guid' => isset($raw['number']) ? 'bayan-'.$raw['number'] : null,
            'name'        => $raw['name']       ?? '',
            'latin_name'  => $raw['latin_name'] ?? null,
            'code'        => $raw['code']       ?? null,
            'barcode'     => $raw['barcode']    ?? null,
            'group_name'  => $raw['group_name'] ?? null,
            'unit'        => $raw['unit']       ?? null,
            'quantity'    => $raw['quantity']   ?? 0,
            'is_active'   => (bool) ($raw['is_active'] ?? true),
            'extra_data'  => array_filter([
                'sel_price'       => $raw['sel_price']       ?? null,
                'regular_price'   => $raw['regular_price']   ?? null,
                'cost_price'      => $raw['cost_price']      ?? null,
                'price_1'         => $raw['price_1']         ?? null,
                'price_2'         => $raw['price_2']         ?? null,
                'price_3'         => $raw['price_3']         ?? null,
                'price_4'         => $raw['price_4']         ?? null,
                'price_5'         => $raw['price_5']         ?? null,
                'price_21'        => $raw['price_21']        ?? null,
                'price_22'        => $raw['price_22']        ?? null,
                'price_23'        => $raw['price_23']        ?? null,
                'price_24'        => $raw['price_24']        ?? null,
                'price_25'        => $raw['price_25']        ?? null,
                'price_31'        => $raw['price_31']        ?? null,
                'price_32'        => $raw['price_32']        ?? null,
                'price_33'        => $raw['price_33']        ?? null,
                'price_34'        => $raw['price_34']        ?? null,
                'price_35'        => $raw['price_35']        ?? null,
                'price_last'      => $raw['price_last']      ?? null,
                'last_price_date' => $raw['last_price_date'] ?? null,
                'origin'          => $raw['origin']          ?? null,
                'group_guid'      => $raw['group_guid']      ?? null,
            ], fn ($v) => $v !== null),
        ];
    }
}
