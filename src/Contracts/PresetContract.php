<?php

namespace SqlSync\LaravelSqlSync\Contracts;

interface PresetContract
{
    /**
     * Map a raw record from the Windows Agent into the standard SqlSync schema.
     *
     * Required keys in the returned array:
     *   - source_guid (string)
     *   - name        (string)
     *
     * Optional standard keys:
     *   - latin_name, code, barcode, group_name, unit,
     *     quantity, is_active, extra_data (array)
     *
     * Any preset-specific fields should go inside extra_data[].
     */
    public function map(array $raw): array;
}
