<?php

declare(strict_types=1);

namespace SqlSync\LaravelSqlSync\Models;

use Illuminate\Database\Eloquent\Model;

class FieldMapping extends Model
{
    protected $table = 'sqlsync_field_mappings';

    protected $fillable = [
        'company_id',
        'preset',
        'source_field',
        'target_label',
        'target_role',
        'is_price',
        'is_unit',
        'is_visible',
        'sort_order',
    ];

    protected $casts = [
        'is_price'   => 'boolean',
        'is_unit'    => 'boolean',
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Default mappings for Al-Ameen — used when no custom mapping exists.
     */
    public static function defaultsForPreset(string $preset): array
    {
        return match ($preset) {
            'al_ameen' => [
                ['source_field' => 'mtRetail',    'target_label' => 'سعر المفرق',      'target_role' => 'retail_price',    'is_price' => true,  'is_unit' => false, 'sort_order' => 1],
                ['source_field' => 'mtWhole',     'target_label' => 'سعر الجملة',      'target_role' => 'wholesale_price', 'is_price' => true,  'is_unit' => false, 'sort_order' => 2],
                ['source_field' => 'mtHalf',      'target_label' => 'سعر نصف جملة',    'target_role' => 'half_price',      'is_price' => true,  'is_unit' => false, 'sort_order' => 3],
                ['source_field' => 'mtEndUser',   'target_label' => 'سعر المستهلك',    'target_role' => 'end_user_price',  'is_price' => true,  'is_unit' => false, 'sort_order' => 4],
                ['source_field' => 'mtHigh',      'target_label' => 'سعر عالي',        'target_role' => 'high_price',      'is_price' => true,  'is_unit' => false, 'sort_order' => 5],
                ['source_field' => 'mtLow',       'target_label' => 'سعر منخفض',       'target_role' => 'low_price',       'is_price' => true,  'is_unit' => false, 'sort_order' => 6],
                ['source_field' => 'mtExport',    'target_label' => 'سعر التصدير',     'target_role' => 'export_price',    'is_price' => true,  'is_unit' => false, 'sort_order' => 7],
                ['source_field' => 'mtUnity',     'target_label' => 'الوحدة الأساسية', 'target_role' => 'unit_1',          'is_price' => false, 'is_unit' => true,  'sort_order' => 8],
                ['source_field' => 'mtUnit2',     'target_label' => 'الوحدة الثانية',  'target_role' => 'unit_2',          'is_price' => false, 'is_unit' => true,  'sort_order' => 9],
                ['source_field' => 'mtUnit2Fact', 'target_label' => 'معامل الوحدة 2',  'target_role' => 'unit_2_factor',   'is_price' => false, 'is_unit' => false, 'sort_order' => 10],
                ['source_field' => 'mtUnit3',     'target_label' => 'الوحدة الثالثة',  'target_role' => 'unit_3',          'is_price' => false, 'is_unit' => true,  'sort_order' => 11],
                ['source_field' => 'mtUnit3Fact', 'target_label' => 'معامل الوحدة 3',  'target_role' => 'unit_3_factor',   'is_price' => false, 'is_unit' => false, 'sort_order' => 12],
            ],
            default => [],
        };
    }
}
