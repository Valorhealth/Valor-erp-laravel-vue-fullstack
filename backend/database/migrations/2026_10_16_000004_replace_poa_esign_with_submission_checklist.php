<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('power_of_attorney_signatures')) {
            Schema::drop('power_of_attorney_signatures');
        }

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'poa_signing_capacity')) {
                $table->dropColumn('poa_signing_capacity');
            }
        });

        Schema::table('quotation_power_of_attorney_versions', function (Blueprint $table): void {
            $columns = collect([
                'signing_status', 'sale_signature_asset_id', 'sale_signature_version_no',
                'sale_signature_image_base64', 'sale_signature_image_sha256',
            ])->filter(fn (string $column): bool => Schema::hasColumn('quotation_power_of_attorney_versions', $column))->all();
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('billing_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('billing_records', 'submission_document_requirements')) {
                $table->jsonb('submission_document_requirements')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing_records', function (Blueprint $table): void {
            if (Schema::hasColumn('billing_records', 'submission_document_requirements')) {
                $table->dropColumn('submission_document_requirements');
            }
        });
    }
};
