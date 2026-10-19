<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_records', function (Blueprint $table): void {
            $table->unsignedBigInteger('quotation_document_version_id')->nullable();
            $table->foreign('quotation_document_version_id', 'billing_records_quotation_document_version_fk')
                ->references('document_version_id')->on('quotation_document_versions')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->index('quotation_document_version_id', 'billing_records_quotation_document_version_idx');
        });

        DB::statement(<<<'SQL'
            UPDATE billing_records AS bill
            SET quotation_document_version_id = latest.document_version_id
            FROM (
                SELECT DISTINCT ON (bill_id) bill_id, document_version_id
                FROM quotation_document_versions
                WHERE bill_id IS NOT NULL AND document_mode = 'original'
                ORDER BY bill_id, created_at DESC, document_version_id DESC
            ) AS latest
            WHERE bill.bill_id = latest.bill_id
        SQL);
    }

    public function down(): void
    {
        Schema::table('billing_records', function (Blueprint $table): void {
            $table->dropForeign('billing_records_quotation_document_version_fk');
            $table->dropIndex('billing_records_quotation_document_version_idx');
            $table->dropColumn('quotation_document_version_id');
        });
    }
};
