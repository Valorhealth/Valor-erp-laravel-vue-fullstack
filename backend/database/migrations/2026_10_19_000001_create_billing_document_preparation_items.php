<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_document_preparation_items', function (Blueprint $table): void {
            $table->increments('preparation_item_id');
            $table->integer('bill_id');
            $table->string('document_type', 64);
            $table->string('status', 24)->default('pending');
            $table->text('admin_note')->nullable();
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('prepared_at')->nullable();
            $table->integer('prepared_by')->nullable();

            $table->unique(['bill_id', 'document_type'], 'billing_document_preparation_unique');
            $table->index(['status', 'requested_at'], 'billing_document_preparation_queue_idx');
            $table->foreign('bill_id')->references('bill_id')->on('billing_records')->cascadeOnDelete();
            $table->foreign('prepared_by')->references('id')->on('users')->nullOnDelete();
        });

        if (! Schema::hasColumn('billing_records', 'submission_document_requirements')) {
            return;
        }

        DB::table('billing_records')
            ->select(['bill_id', 'submission_document_requirements', 'submitted_at'])
            ->whereNotNull('submission_document_requirements')
            ->whereNotIn('status', ['ฉบับร่าง', 'รอแก้ไข', 'ไม่อนุมัติ', 'ยกเลิกบิล', 'ดำเนินการเสร็จสิ้น'])
            ->orderBy('bill_id')
            ->chunkById(200, function ($bills): void {
                foreach ($bills as $bill) {
                    $documents = is_array($bill->submission_document_requirements)
                        ? $bill->submission_document_requirements
                        : json_decode((string) $bill->submission_document_requirements, true);
                    foreach (array_unique(array_filter((array) $documents, 'is_string')) as $documentType) {
                        DB::table('billing_document_preparation_items')->insertOrIgnore([
                            'bill_id' => $bill->bill_id,
                            'document_type' => $documentType,
                            'status' => 'pending',
                            'requested_at' => $bill->submitted_at ?: now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }, 'bill_id');
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_document_preparation_items');
    }
};
