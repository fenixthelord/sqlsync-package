<?php

namespace SqlSync\LaravelSqlSync\Presets;

use SqlSync\LaravelSqlSync\Contracts\PresetContract;

/**
 * Maps records from Al-Ameen (الأمين) accounting software.
 * Source view: vwMtPrices
 */
class AlAmeenPreset implements PresetContract
{
    public function map(array $raw): array
    {
        return [
            'source_guid' => $raw['guid']        ?? null,
            'name'        => $raw['name']         ?? '',
            'latin_name'  => $raw['latin_name']   ?? null,
            'code'        => $raw['code']         ?? null,
            'barcode'     => $raw['barcode']      ?? null,
            'group_name'  => $raw['group']        ?? null,
            'unit'        => $raw['unit']         ?? null,
            'quantity'    => $raw['quantity']     ?? 0,
            'is_active'   => (bool) ($raw['is_active'] ?? true),
            'extra_data'  => [
                'number'     => $raw['number']     ?? null,
                'origin'     => $raw['origin']     ?? null,
                'price_1'    => $raw['price_1']    ?? null,
                'price_2'    => $raw['price_2']    ?? null,
                'price_3'    => $raw['price_3']    ?? null,
                'price_4'    => $raw['price_4']    ?? null,
                'price_5'    => $raw['price_5']    ?? null,
                'price_6'    => $raw['price_6']    ?? null,
                'multiple'   => $raw['multiple']   ?? null,
            ],
        ];
    }
}
