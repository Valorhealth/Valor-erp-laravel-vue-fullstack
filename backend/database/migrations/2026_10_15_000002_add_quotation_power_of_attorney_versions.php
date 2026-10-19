<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_power_of_attorney_versions', function (Blueprint $table): void {
            $table->bigIncrements('authorization_version_id');
            $table->unsignedBigInteger('document_version_id')->unique();
            $table->integer('qt_id');
            $table->integer('bill_id');
            $table->string('authorization_no', 100);
            $table->jsonb('payload');
            $table->integer('sale_user_id')->nullable();
            $table->string('sale_username', 255);
            $table->longText('draft_pdf_base64');
            $table->string('draft_pdf_sha256', 64);
            $table->string('filename', 500);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('document_version_id')->references('document_version_id')->on('quotation_document_versions')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('qt_id')->references('qt_id')->on('quotations')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('bill_id')->references('bill_id')->on('billing_records')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('sale_user_id')->references('id')->on('users')->nullOnDelete()->cascadeOnUpdate();
            $table->index(['bill_id', 'created_at'], 'quotation_poa_bill_created_idx');
        });

        DB::statement("ALTER TABLE quotation_power_of_attorney_versions ADD CONSTRAINT quotation_poa_payload_object_check CHECK (JSONB_TYPEOF(payload) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_power_of_attorney_versions');
    }
};
