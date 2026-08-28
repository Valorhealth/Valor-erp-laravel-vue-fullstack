<?php

namespace App\Services\Documents;

use App\Models\BillingDocument;
use App\Models\BillingRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class BillingDocumentStorage
{
    public function storeUpload(BillingRecord $bill, string $documentType, UploadedFile $file, string $actor): BillingDocument
    {
        $bytes = file_get_contents($file->getRealPath());
        abort_if($bytes === false, 422, 'อ่านไฟล์ไม่สำเร็จ');

        return $this->storeBytes(
            $bill,
            $documentType,
            $file->getClientOriginalName(),
            $file->getMimeType() ?: 'application/octet-stream',
            $bytes,
            $actor,
        );
    }

    public function storeBytes(
        BillingRecord $bill,
        string $documentType,
        string $fileName,
        string $mimeType,
        string $bytes,
        string $actor,
    ): BillingDocument {
        return DB::transaction(function () use ($bill, $documentType, $fileName, $mimeType, $bytes, $actor): BillingDocument {
            $latest = DB::table('billing_documents')
                ->where('bill_id', $bill->bill_id)
                ->where('document_type', $documentType)
                ->lockForUpdate()
                ->orderByDesc('version_no')
                ->first();

            DB::table('billing_documents')
                ->where('bill_id', $bill->bill_id)
                ->where('document_type', $documentType)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'superseded_at' => now(),
                    'superseded_by' => $actor,
                ]);

            $inserted = DB::selectOne(
                "INSERT INTO billing_documents
                    (bill_id, document_type, file_name, mime_type, file_bytes, version_no, sha256, is_current, uploaded_by, uploaded_at)
                 VALUES (?, ?, ?, ?, decode(?, 'base64'), ?, ?, TRUE, ?, CURRENT_TIMESTAMP)
                 RETURNING doc_id",
                [
                    $bill->bill_id,
                    $documentType,
                    $fileName,
                    $mimeType,
                    base64_encode($bytes),
                    ((int) ($latest->version_no ?? 0)) + 1,
                    hash('sha256', $bytes),
                    $actor,
                ],
            );

            return BillingDocument::query()
                ->select(['doc_id', 'bill_id', 'document_type', 'file_name', 'mime_type', 'version_no', 'sha256', 'uploaded_by', 'uploaded_at'])
                ->findOrFail((int) $inserted->doc_id);
        });
    }
}
