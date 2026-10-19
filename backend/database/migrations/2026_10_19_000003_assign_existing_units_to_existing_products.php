<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $units = DB::table('product_units')->orderBy('unit_id')->get(['unit_id', 'unit_name']);
        if ($units->isEmpty()) {
            return;
        }

        $now = now();
        DB::table('master_products')->select('sku_code')->orderBy('sku_code')->chunk(250, function ($products) use ($units, $now): void {
            $rows = [];
            foreach ($products as $product) {
                foreach ($units as $unit) {
                    $rows[] = [
                        'sku_code' => $product->sku_code,
                        'unit_id' => $unit->unit_id,
                        'is_default' => $unit->unit_name === 'ชิ้น',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            DB::table('master_product_units')->insertOrIgnore($rows);
        });
    }

    public function down(): void
    {
        $pieceId = DB::table('product_units')->where('unit_name', 'ชิ้น')->value('unit_id');
        DB::table('master_product_units')->when($pieceId, fn ($query) => $query->where('unit_id', '<>', $pieceId))->delete();
    }
};
