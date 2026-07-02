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
                // Prices — original mtPrices column names
                'mtHigh'      => $raw['mtHigh']      ?? null,
                'mtLow'       => $raw['mtLow']        ?? null,
                'mtWhole'     => $raw['mtWhole']      ?? null,
                'mtHalf'      => $raw['mtHalf']       ?? null,
                'mtRetail'    => $raw['mtRetail']     ?? null,
                'mtEndUser'   => $raw['mtEndUser']    ?? null,
                'mtExport'    => $raw['mtExport']     ?? null,
                'mtVendor'    => $raw['mtVendor']     ?? null,
                // Units
                'mtUnity'     => $raw['mtUnity']      ?? null,
                'mtUnit2'     => $raw['mtUnit2']      ?? null,
                'mtUnit2Fact' => $raw['mtUnit2Fact']  ?? null,
                'mtUnit3'     => $raw['mtUnit3']      ?? null,
                'mtUnit3Fact' => $raw['mtUnit3Fact']  ?? null,
                // Extra
                'mtOrigin'    => $raw['mtOrigin']     ?? null,
                'mtPriceType' => $raw['mtPriceType']  ?? null,
                'mtSellType'  => $raw['mtSellType']   ?? null,
            ], fn($v) => $v !== null),
        ];
    }
}
