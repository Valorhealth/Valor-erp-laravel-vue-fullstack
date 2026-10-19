<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingRecord;
use App\Models\Quotation;
use App\Models\QuotationDocumentVersion;
use App\Services\Authorization\RoleAccess;
use App\Services\Billing\BillingWorkflow;
use App\Services\Documents\BillingDocumentStorage;
use App\Services\Documents\DocumentDownloadAudit;
use App\Services\Documents\DocumentService;
use App\Services\Notifications\WorkflowEmailNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BillingDocumentController extends Controller
{
    public function store(Request $request, BillingRecord $bill, BillingWorkflow $workflow, WorkflowEmailNotifier $notifier, BillingDocumentStorage $storage): JsonResponse
    {
        return $this->storeDocument($request, $bill, $workflow, $notifier, $storage, 'customer_po', 'pdf,png,jpg,jpeg,xlsx');
    }

    public function storeSignedQuotation(Request $request, BillingRecord $bill, BillingWorkflow $workflow, WorkflowEmailNotifier $notifier, BillingDocumentStorage $storage): JsonResponse
    {
        return $this->storeDocument($request, $bill, $workflow, $notifier, $storage, 'signed_quotation', 'pdf,png,jpg,jpeg');
    }

    private function storeDocument(
        Request $request,
        BillingRecord $bill,
        BillingWorkflow $workflow,
        WorkflowEmailNotifier $notifier,
        BillingDocumentStorage $storage,
        string $documentType,
        string $mimes,
    ): JsonResponse {
        $this->authorizeRead($request, $bill);
        abort_unless(in_array($bill->status, [...BillingWorkflow::SALE_EDITABLE_STATUSES, 'รอลูกค้ายืนยัน'], true), 422, 'แนบหลักฐานได้เฉพาะ Draft รายการตีกลับ หรือรายการที่รอลูกค้ายืนยัน');
        $file = $request->validate(['file' => ['required', 'file', 'max:10240', "mimes:{$mimes}"]])['file'];
        $document = $storage->storeUpload($bill, $documentType, $file, $request->user()->username);

        if ($bill->status === 'รอลูกค้ายืนยัน') {
            $bill = $workflow->confirmCustomerEvidence($request->user(), $bill);
            $notifier->customerEvidenceReceived($bill, $request->user());
        }

        return response()->json(['data' => $document], 201);
    }

    public function show(Request $request, BillingRecord $bill): mixed
    {
        return $this->showDocument($request, $bill, 'customer_po');
    }

    public function showSignedQuotation(Request $request, BillingRecord $bill, DocumentService $documents): mixed
    {
        $this->authorizeRead($request, $bill);
        $stored = DB::selectOne("SELECT doc_id AS document_id, version_no, file_name, mime_type, encode(file_bytes, 'base64') AS encoded FROM billing_documents WHERE bill_id = ? AND document_type = 'signed_quotation' AND is_current = TRUE ORDER BY version_no DESC LIMIT 1", [$bill->bill_id]);
        if ($stored) {
            DocumentDownloadAudit::capture($request, 'signed_quotation_upload', bill: $bill, extra: ['document_id' => $stored->document_id, 'file_version' => $stored->version_no]);

            return response(base64_decode((string) $stored->encoded, true), 200, [
                'Content-Type' => $stored->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename*=UTF-8\'\''.rawurlencode($stored->file_name),
            ]);
        }

        abort_unless($bill->ref_qt_id && $bill->customerAcceptanceSignatures()->whereNull('revoked_at')->exists(), 404);
        $bill->load(['items', 'documents', 'signatures', 'customerAcceptanceSignatures']);
        $quotation = $bill->ref_qt_id ? Quotation::query()->with(['items', 'creator:id,username,first_name,last_name,phone,department'])->findOrFail($bill->ref_qt_id) : null;
        $confirmed = $bill->quotation_document_version_id
            ? QuotationDocumentVersion::query()->where('document_version_id', $bill->quotation_document_version_id)->where('bill_id', $bill->bill_id)->first()
            : null;
        $bytes = $confirmed
            ? $documents->confirmedQuotationVersionPdf($confirmed, $bill)
            : $documents->quotationPdf($quotation, draft: $bill);
        DocumentDownloadAudit::capture($request, 'signed_quotation', $quotation, $bill);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $documents->attachmentDisposition('Signed_Quotation_'.$quotation->qt_no.'_R'.max(1, (int) $bill->revision_no).'.pdf'),
        ]);
    }

    private function showDocument(Request $request, BillingRecord $bill, string $documentType): mixed
    {
        $this->authorizeRead($request, $bill);
        $document = DB::selectOne("SELECT doc_id AS document_id, version_no, file_name, mime_type, encode(file_bytes, 'base64') AS encoded FROM billing_documents WHERE bill_id = ? AND document_type = ? AND is_current = TRUE ORDER BY version_no DESC LIMIT 1", [$bill->bill_id, $documentType]);
        abort_unless($document, 404);
        DocumentDownloadAudit::capture($request, $documentType, bill: $bill, extra: ['document_id' => $document->document_id, 'file_version' => $document->version_no]);

        return response(base64_decode((string) $document->encoded, true), 200, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename*=UTF-8\'\''.rawurlencode($document->file_name),
        ]);
    }

    private function authorizeRead(Request $request, BillingRecord $bill): void
    {
        if (RoleAccess::mustUseOwnBillingRecords($request->user()->role)
            && (int) ($bill->creator_user_id ?? 0) !== (int) $request->user()->id
            && ! ($bill->creator_user_id === null && $bill->created_by === $request->user()->username)) {
            abort(403, 'คุณไม่มีสิทธิ์เข้าถึงเอกสารนี้');
        }
    }
}
