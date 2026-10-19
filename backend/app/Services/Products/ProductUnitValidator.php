<?php

namespace App\Services\Products;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductUnitValidator
{
    /** @param array<int, array<string, mixed>> $items */
    public function validate(array $items): void
    {
        $allowed = DB::table('master_product_units as assignment')
            ->join('product_units as unit', 'unit.unit_id', '=', 'assignment.unit_id')
            ->whereIn('assignment.sku_code', collect($items)->pluck('main_category')->filter()->unique())
            ->get(['assignment.sku_code', 'unit.unit_name'])
            ->mapWithKeys(fn ($row): array => [$row->sku_code."\0".$row->unit_name => true]);

        $errors = [];
        foreach ($items as $index => $item) {
            $pair = trim((string) ($item['main_category'] ?? ''))."\0".trim((string) ($item['unit_type'] ?? ''));
            if (! $allowed->has($pair)) {
                $errors["items.{$index}.unit_type"] = ['หน่วยสินค้านี้ไม่ได้ถูกกำหนดไว้สำหรับ SKU ที่เลือก'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
