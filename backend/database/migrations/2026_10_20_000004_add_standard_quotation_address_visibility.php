<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['quotations', 'billing_records'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->boolean('standard_show_billing_address')->default(true);
                $table->boolean('standard_show_delivery_address')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['billing_records', 'quotations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['standard_show_billing_address', 'standard_show_delivery_address']);
            });
        }
    }
};
