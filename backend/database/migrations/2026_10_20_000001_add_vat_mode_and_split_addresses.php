<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['quotations', 'billing_records'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('price_vat_mode', 16)->default('in_vat');
                $table->text('billing_address')->nullable();
                $table->text('delivery_address')->nullable();
            });
            DB::table($tableName)->update([
                'billing_address' => DB::raw('address_note'),
                'delivery_address' => DB::raw('address_note'),
            ]);
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->text('billing_address')->nullable();
            $table->text('delivery_address')->nullable();
            $table->string('province', 255)->nullable();
            $table->string('district', 255)->nullable();
            $table->string('sub_district', 255)->nullable();
            $table->string('zipcode', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['billing_address', 'delivery_address', 'province', 'district', 'sub_district', 'zipcode']);
        });
        foreach (['billing_records', 'quotations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['price_vat_mode', 'billing_address', 'delivery_address']);
            });
        }
    }
};
