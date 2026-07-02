<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Presets;

use SqlSync\LaravelSqlSync\Contracts\PresetContract;

/**
 * Maps records from Al-Ameen (الأمين) — source: vwMtPrices
 * extra_data uses original column names so FieldMapping can resolve them.
 */
class AlAmeenPreset implements PresetContract
{
    public function map(array $raw): array
    {
        return [
            'source_guid' => $raw['guid']       ?? null,
            'name'        => $raw['name']        ?? '',
            'latin_name'  => $raw['latin_name']  ?? null,
            'code'        => $raw['code']        ?? null,
            'barcode'     => $raw['barcode']     ?? null,
            'group_name'  => $raw['group_name']  ?? null,
            'unit'        => $raw['unit']        ?? null,
            'quantity'    => $raw['quantity']    ?? 0,
            'is_active'   => (bool) ($raw['is_active'] ?? true),
            'extra_data'  => array_filter([
                // Prices — actual raw payload column names from the Agent
                'price_whole'     => $raw['price_whole']     ?? null,
                'price_half'      => $raw['price_half']      ?? null,
                'price_retail'    => $raw['price_retail']    ?? null,
                'price_end_user'  => $raw['price_end_user']  ?? null,
                'price_vendor'    => $raw['price_vendor']    ?? null,
                'price_avg'       => $raw['price_avg']       ?? null,
                'price_last'      => $raw['price_last']      ?? null,
                'last_price_date' => $raw['last_price_date'] ?? null,
                // Extra
                'origin'      => $raw['origin']      ?? null,
                // Units — kept for accounts that expose these columns
                'mtUnity'     => $raw['mtUnity']      ?? null,
                'mtUnit2'     => $raw['mtUnit2']      ?? null,
                'mtUnit2Fact' => $raw['mtUnit2Fact']  ?? null,
                'mtUnit3'     => $raw['mtUnit3']      ?? null,
                'mtUnit3Fact' => $raw['mtUnit3Fact']  ?? null,
            ], fn($v) => $v !== null),
        ];
    }
}
