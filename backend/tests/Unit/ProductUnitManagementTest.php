<?php

namespace Tests\Unit;

use App\Services\Products\ProductUnitValidator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductUnitManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('master_products', function (Blueprint $table): void {
            $table->string('sku_code')->primary();
            $table->string('sku_name');
            $table->string('sku_e_name')->nullable();
        });
        Schema::create('salesmen', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('salesman_code')->unique();
            $table->string('salesman_name');
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username')->unique();
        });
        DB::table('master_products')->insert(['sku_code' => 'SKU-1', 'sku_name' => 'Product']);

        $migration = require database_path('migrations/2026_10_19_000002_add_product_units_and_user_salesman_link.php');
        $migration->up();
        $assignmentMigration = require database_path('migrations/2026_10_19_000003_assign_existing_units_to_existing_products.php');
        $assignmentMigration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('master_product_units');
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('salesmen');
        Schema::dropIfExists('master_products');
        parent::tearDown();
    }

    public function test_migration_seeds_units_and_assigns_piece_as_product_default(): void
    {
        $this->assertDatabaseCount('product_units', 3);
        $this->assertDatabaseCount('master_product_units', 3);
        $this->assertDatabaseHas('master_product_units', [
            'sku_code' => 'SKU-1',
            'unit_id' => DB::table('product_units')->where('unit_name', 'ชิ้น')->value('unit_id'),
            'is_default' => true,
        ]);
        $this->assertTrue(Schema::hasColumn('users', 'salesman_id'));
    }

    public function test_validator_rejects_a_unit_not_assigned_to_the_product(): void
    {
        $validator = app(ProductUnitValidator::class);
        $validator->validate([['main_category' => 'SKU-1', 'unit_type' => 'ชิ้น']]);
        $this->addToAssertionCount(1);

        DB::table('product_units')->insert(['unit_name' => 'ลัง', 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(ValidationException::class);
        $validator->validate([['main_category' => 'SKU-1', 'unit_type' => 'ลัง']]);
    }
}
