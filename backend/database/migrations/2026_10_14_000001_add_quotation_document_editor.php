<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_document_drafts', function (Blueprint $table): void {
            $table->bigIncrements('draft_id');
            $table->integer('qt_id');
            $table->string('template_id', 64);
            $table->string('document_mode', 20)->default('original');
            $table->jsonb('payload');
            $table->unsignedInteger('lock_version')->default(1);
            $table->integer('updated_by_user_id')->nullable();
            $table->string('updated_by', 255);
            $table->timestampsTz();

            $table->foreign('qt_id')->references('qt_id')->on('quotations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('updated_by_user_id')->references('id')->on('users')->cascadeOnUpdate()->nullOnDelete();
            $table->unique(['qt_id', 'template_id', 'document_mode'], 'quotation_document_drafts_scope_unique');
            $table->index(['qt_id', 'updated_at'], 'quotation_document_drafts_updated_idx');
        });

        Schema::create('quotation_document_versions', function (Blueprint $table): void {
            $table->bigIncrements('document_version_id');
            $table->integer('qt_id');
            $table->integer('bill_id')->nullable();
            $table->string('template_id', 64);
            $table->string('document_mode', 20)->default('original');
            $table->unsignedInteger('revision_no');
            $table->jsonb('payload');
            $table->longText('pdf_base64');
            $table->string('pdf_sha256', 64);
            $table->string('filename', 500);
            $table->integer('confirmed_by_user_id')->nullable();
            $table->string('confirmed_by', 255);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('qt_id')->references('qt_id')->on('quotations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('bill_id')->references('bill_id')->on('billing_records')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('confirmed_by_user_id')->references('id')->on('users')->cascadeOnUpdate()->nullOnDelete();
            $table->unique(['qt_id', 'template_id', 'document_mode', 'revision_no'], 'quotation_document_versions_revision_unique');
            $table->index(['qt_id', 'created_at'], 'quotation_document_versions_created_idx');
        });

        DB::statement("ALTER TABLE quotation_document_drafts ADD CONSTRAINT quotation_document_drafts_mode_check CHECK (document_mode IN ('original', 'copy', 'revised'))");
        DB::statement("ALTER TABLE quotation_document_versions ADD CONSTRAINT quotation_document_versions_mode_check CHECK (document_mode IN ('original', 'copy', 'revised'))");
        DB::statement("ALTER TABLE quotation_document_drafts ADD CONSTRAINT quotation_document_drafts_payload_object_check CHECK (JSONB_TYPEOF(payload) = 'object')");
        DB::statement("ALTER TABLE quotation_document_versions ADD CONSTRAINT quotation_document_versions_payload_object_check CHECK (JSONB_TYPEOF(payload) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_document_versions');
        Schema::dropIfExists('quotation_document_drafts');
    }
};
