<?php

namespace App\Services\Products;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductUnitValidator
{
    /** @param array<int, array<string, mixed>> $items */
    public function validate(array $items): void
    {
        $allowed = DB::table('product_units')
            ->pluck('unit_name')
            ->mapWithKeys(fn (string $unit): array => [trim($unit) => true]);

        $errors = [];
        foreach ($items as $index => $item) {
            $unit = trim((string) ($item['unit_type'] ?? ''));
            if ($unit === '' || ! $allowed->has($unit)) {
                $errors["items.{$index}.unit_type"] = ['ไม่พบหน่วยนี้ในระบบ กรุณาติดต่อ Admin เพื่อเพิ่มหน่วยก่อนบันทึก'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
