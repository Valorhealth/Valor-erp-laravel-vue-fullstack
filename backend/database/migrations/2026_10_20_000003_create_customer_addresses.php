<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->bigIncrements('address_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('bplus_address_code', 255)->nullable();
            $table->string('address_name', 500);
            $table->string('address_type', 20)->default('both');
            $table->text('full_address');
            $table->string('province', 255)->nullable();
            $table->string('district', 255)->nullable();
            $table->string('sub_district', 255)->nullable();
            $table->string('zipcode', 20)->nullable();
            $table->string('tax_id', 30)->nullable();
            $table->string('tax_branch_code', 20)->nullable();
            $table->string('contact_name', 500)->nullable();
            $table->string('contact_phone', 100)->nullable();
            $table->text('delivery_note')->nullable();
            $table->boolean('is_default_billing')->default(false);
            $table->boolean('is_default_delivery')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('bplus_synced_at')->nullable();
            $table->timestamps();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->unique(['customer_id', 'bplus_address_code']);
            $table->index(['customer_id', 'is_active']);
        });

        foreach (['quotations', 'billing_records'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('billing_address_id')->nullable();
                $table->unsignedBigInteger('delivery_address_id')->nullable();
                $table->foreign('billing_address_id')->references('address_id')->on('customer_addresses')->nullOnDelete();
                $table->foreign('delivery_address_id')->references('address_id')->on('customer_addresses')->nullOnDelete();
            });
        }

        DB::table('customers')->orderBy('id')->each(function (object $customer): void {
            $billing = trim((string) ($customer->billing_address ?? ''));
            $delivery = trim((string) ($customer->delivery_address ?? ''));
            if ($billing !== '') {
                DB::table('customer_addresses')->insert([
                    'customer_id' => $customer->id, 'address_name' => 'ที่อยู่ออกบิลเดิม',
                    'address_type' => $delivery === '' || $delivery === $billing ? 'both' : 'billing',
                    'full_address' => $billing, 'province' => $customer->province, 'district' => $customer->district,
                    'sub_district' => $customer->sub_district, 'zipcode' => $customer->zipcode,
                    'tax_id' => $customer->tax_id, 'is_default_billing' => true,
                    'is_default_delivery' => $delivery === '' || $delivery === $billing,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($delivery !== '' && $delivery !== $billing) {
                DB::table('customer_addresses')->insert([
                    'customer_id' => $customer->id, 'address_name' => 'ที่อยู่จัดส่งเดิม',
                    'address_type' => 'delivery', 'full_address' => $delivery,
                    'province' => $customer->province, 'district' => $customer->district,
                    'sub_district' => $customer->sub_district, 'zipcode' => $customer->zipcode,
                    'is_default_billing' => false, 'is_default_delivery' => true,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        foreach (['billing_records', 'quotations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['billing_address_id']);
                $table->dropForeign(['delivery_address_id']);
                $table->dropColumn(['billing_address_id', 'delivery_address_id']);
            });
        }
        Schema::dropIfExists('customer_addresses');
    }
};
