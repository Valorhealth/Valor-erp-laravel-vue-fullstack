<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table): void {
            $table->increments('unit_id');
            $table->string('unit_name', 100)->unique();
            $table->timestampsTz();
        });

        Schema::create('master_product_units', function (Blueprint $table): void {
            $table->string('sku_code', 255);
            $table->unsignedInteger('unit_id');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->primary(['sku_code', 'unit_id']);
            $table->index(['sku_code', 'is_default']);
            $table->foreign('sku_code')->references('sku_code')->on('master_products')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('unit_id')->references('unit_id')->on('product_units')->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('salesman_id')->nullable()->unique();
            $table->foreign('salesman_id')->references('id')->on('salesmen')->nullOnDelete();
        });

        $now = now();
        foreach (['ชิ้น', 'กล่อง/30ชิ้น', 'กล่องใหญ่/60ชิ้น'] as $unitName) {
            DB::table('product_units')->insertOrIgnore([
                'unit_name' => $unitName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $defaultUnitId = DB::table('product_units')->where('unit_name', 'ชิ้น')->value('unit_id');
        if ($defaultUnitId) {
            DB::table('master_products')->select('sku_code')->orderBy('sku_code')->chunk(500, function ($products) use ($defaultUnitId, $now): void {
                DB::table('master_product_units')->insertOrIgnore($products->map(fn ($product): array => [
                    'sku_code' => $product->sku_code,
                    'unit_id' => $defaultUnitId,
                    'is_default' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['salesman_id']);
            $table->dropUnique(['salesman_id']);
            $table->dropColumn('salesman_id');
        });
        Schema::dropIfExists('master_product_units');
        Schema::dropIfExists('product_units');
    }
};
