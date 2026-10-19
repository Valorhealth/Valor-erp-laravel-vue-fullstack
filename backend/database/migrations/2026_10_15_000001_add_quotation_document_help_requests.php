<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_document_help_requests', function (Blueprint $table): void {
            $table->bigIncrements('help_request_id');
            $table->integer('qt_id');
            $table->string('template_id', 64);
            $table->string('document_mode', 20)->default('original');
            $table->text('request_note');
            $table->string('status', 20)->default('pending');
            $table->integer('requested_by_user_id')->nullable();
            $table->string('requested_by', 255);
            $table->integer('assigned_to_user_id')->nullable();
            $table->string('assigned_to', 255)->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->foreign('qt_id')->references('qt_id')->on('quotations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('requested_by_user_id')->references('id')->on('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('assigned_to_user_id')->references('id')->on('users')->cascadeOnUpdate()->nullOnDelete();
            $table->index(['status', 'requested_at'], 'quotation_help_status_requested_idx');
            $table->index(['qt_id', 'template_id', 'document_mode'], 'quotation_help_document_scope_idx');
        });

        DB::statement("ALTER TABLE quotation_document_help_requests ADD CONSTRAINT quotation_help_mode_check CHECK (document_mode IN ('original', 'copy', 'revised'))");
        DB::statement("ALTER TABLE quotation_document_help_requests ADD CONSTRAINT quotation_help_status_check CHECK (status IN ('pending', 'in_progress', 'resolved', 'cancelled'))");
        DB::statement("CREATE UNIQUE INDEX quotation_help_one_active_idx ON quotation_document_help_requests (qt_id, template_id, document_mode) WHERE status IN ('pending', 'in_progress')");
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_document_help_requests');
    }
};
