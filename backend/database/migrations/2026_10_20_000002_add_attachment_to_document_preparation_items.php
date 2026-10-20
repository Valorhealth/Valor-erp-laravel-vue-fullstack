<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_document_preparation_items', function (Blueprint $table): void {
            $table->integer('attachment_document_id')->nullable()->after('admin_note');
            $table->foreign('attachment_document_id')
                ->references('doc_id')
                ->on('billing_documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_document_preparation_items', function (Blueprint $table): void {
            $table->dropForeign(['attachment_document_id']);
            $table->dropColumn('attachment_document_id');
        });
    }
};
