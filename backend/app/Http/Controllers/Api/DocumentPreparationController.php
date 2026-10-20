<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingDocumentPreparationItem;
use App\Models\BillingRecord;
use App\Models\EditLog;
use App\Models\User;
use App\Services\Authorization\RoleAccess;
use App\Services\Documents\BillingDocumentStorage;
use App\Services\Documents\DocumentService;
use App\Services\Notifications\InAppNotificationPublisher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class DocumentPreparationController extends Controller
{
    private const ACTIVE_STATUSES = ['pending', 'preparing', 'prepared'];

    public function index(Request $request): JsonResponse
    {
        return $this->queueResponse($request);
    }

    public function mine(Request $request): JsonResponse
    {
        return $this->queueResponse($request, true);
    }

    private function queueResponse(Request $request, bool $ownedOnly = false): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $recordId = preg_match('/^#?(\d+)$/', $search, $matches) ? (int) $matches[1] : null;
        $perPage = min(max((int) $request->query('per_page', 20), 5), 100);

        $records = BillingRecord::query()
            ->select(['bill_id', 'ref_qt_id', 'buyer_name', 'created_by', 'status', 'submitted_at'])
            ->whereNotIn('status', ['ฉบับร่าง', 'รอแก้ไข', 'ไม่อนุมัติ', 'ยกเลิกบิล', 'ดำเนินการเสร็จสิ้น'])
            ->whereHas('documentPreparationItems', fn ($items) => $items->whereIn('status', self::ACTIVE_STATUSES))
            ->when($ownedOnly, fn (Builder $query) => $query->where(
                fn (Builder $owned) => $owned->where('creator_user_id', $request->user()->id)
                    ->orWhere(fn (Builder $legacy) => $legacy->whereNull('creator_user_id')->where('created_by', $request->user()->username))
            ))
            ->when($search !== '', function ($query) use ($search, $recordId): void {
                $query->where(function ($nested) use ($search, $recordId): void {
                    $nested->where('buyer_name', 'ilike', "%{$search}%")
                        ->orWhere('created_by', 'ilike', "%{$search}%")
                        ->orWhereHas('quotation', fn ($quotation) => $quotation->where('qt_no', 'ilike', "%{$search}%"));
                    if ($recordId !== null) {
                        $nested->orWhere('bill_id', $recordId);
                    }
                });
            })
            ->with([
                'quotation:qt_id,qt_no',
                'documentPreparationItems' => fn ($items) => $items
                    ->select([
                        'preparation_item_id', 'bill_id', 'document_type', 'status', 'admin_note', 'attachment_document_id',
                        'requested_at', 'updated_at', 'prepared_at', 'prepared_by',
                    ])
                    ->whereIn('status', self::ACTIVE_STATUSES)
                    ->with([
                        'preparedByUser:id,username,first_name,last_name',
                        'attachmentDocument:doc_id,bill_id,file_name,mime_type,version_no,sha256,uploaded_by,uploaded_at',
                    ])
                    ->orderBy('preparation_item_id'),
            ])
            ->orderByDesc(
                BillingDocumentPreparationItem::query()
                    ->selectRaw('MAX(requested_at)')
                    ->whereColumn('bill_id', 'billing_records.bill_id')
                    ->whereIn('status', self::ACTIVE_STATUSES)
            )
            ->orderByDesc('bill_id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($records);
    }

    public function update(Request $request, BillingDocumentPreparationItem $preparationItem, InAppNotificationPublisher $notifications): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::ACTIVE_STATUSES)],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $user = $request->user();
        $status = $data['status'];
        $wasPrepared = $preparationItem->status === 'prepared';
        $preparationItem->forceFill([
            'status' => $status,
            'admin_note' => trim((string) ($data['admin_note'] ?? '')) ?: null,
            'updated_at' => now(),
            'prepared_at' => $status === 'prepared' ? now() : null,
            'prepared_by' => $status === 'prepared' ? $user->id : null,
        ])->save();

        EditLog::query()->create([
            'bill_id' => $preparationItem->bill_id,
            'edited_by' => $user->username,
            'edit_time' => now(),
            'edit_summary' => "อัปเดตงานจัดเตรียมเอกสาร {$preparationItem->document_type} เป็น {$status}",
        ]);

        if (! $wasPrepared && $status === 'prepared') {
            $bill = $preparationItem->bill()->first(['bill_id', 'ref_qt_id', 'buyer_name', 'created_by', 'creator_user_id']);
            $saleId = $bill?->creator_user_id
                ?: User::query()->where('username', $bill?->created_by)->value('id');
            if ($saleId) {
                $documentLabel = match ($preparationItem->document_type) {
                    'quotation' => 'ใบเสนอราคา',
                    'power_of_attorney' => 'Draft หนังสือมอบอำนาจ',
                    'company_affidavit' => 'หนังสือรับรองบริษัท',
                    'vat_registration' => 'ภ.พ.20',
                    'grantor_id_copies' => 'สำเนาบัตรผู้มอบอำนาจ',
                    'representative_id_copy' => 'สำเนาบัตรผู้แทน',
                    'product_documents' => 'เอกสารสินค้า / ใบอนุญาต / Catalog',
                    default => 'เอกสารอื่นตามข้อกำหนด',
                };
                $notifications->publishToUsers(
                    [(int) $saleId],
                    "เอกสารพร้อมแล้ว: {$documentLabel}",
                    "เลขที่รายการ: {$bill->bill_id}\nลูกค้า: {$bill->buyer_name}\n{$documentLabel} พร้อมส่งหรือพร้อมให้มารับแล้ว",
                    'document_preparation',
                    (string) $preparationItem->preparation_item_id,
                );
            }
        }

        return response()->json(['data' => $preparationItem->fresh([
            'preparedByUser:id,username,first_name,last_name',
            'attachmentDocument:doc_id,bill_id,file_name,mime_type,version_no,sha256,uploaded_by,uploaded_at',
        ])]);
    }

    public function storeAttachment(
        Request $request,
        BillingDocumentPreparationItem $preparationItem,
        BillingDocumentStorage $storage,
    ): JsonResponse {
        $file = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,png,jpg,jpeg,doc,docx,xls,xlsx'],
        ])['file'];
        $bill = $preparationItem->bill()->firstOrFail();
        $documentType = 'preparation_'.$preparationItem->preparation_item_id;
        $document = $storage->storeUpload($bill, $documentType, $file, $request->user()->username);

        $preparationItem->forceFill([
            'attachment_document_id' => $document->doc_id,
            'status' => $preparationItem->status === 'prepared' ? 'prepared' : 'preparing',
            'updated_at' => now(),
        ])->save();

        EditLog::query()->create([
            'bill_id' => $preparationItem->bill_id,
            'edited_by' => $request->user()->username,
            'edit_time' => now(),
            'edit_summary' => "อัปโหลดเอกสาร {$preparationItem->document_type}: {$document->file_name}",
        ]);

        return response()->json([
            'data' => $preparationItem->fresh([
                'preparedByUser:id,username,first_name,last_name',
                'attachmentDocument:doc_id,bill_id,file_name,mime_type,version_no,sha256,uploaded_by,uploaded_at',
            ]),
        ], 201);
    }

    public function downloadAttachment(
        Request $request,
        BillingDocumentPreparationItem $preparationItem,
        DocumentService $documents,
    ): mixed {
        $bill = $preparationItem->bill()->firstOrFail();
        $isQueueAdmin = RoleAccess::canUser($request->user(), 'document_preparation_queue', 'system_admin');
        if (! $isQueueAdmin) {
            $owned = (int) ($bill->creator_user_id ?? 0) === (int) $request->user()->id
                || ($bill->creator_user_id === null && $bill->created_by === $request->user()->username);
            abort_unless($owned, 403, 'คุณไม่มีสิทธิ์เข้าถึงเอกสารนี้');
            abort_unless($preparationItem->status === 'prepared', 422, 'Admin ยังไม่ได้ยืนยันว่าเอกสารพร้อม');
        }

        if ($preparationItem->attachment_document_id) {
            $stored = DB::selectOne(
                "SELECT file_name, mime_type, encode(file_bytes, 'base64') AS encoded
                 FROM billing_documents WHERE doc_id = ? AND bill_id = ? LIMIT 1",
                [$preparationItem->attachment_document_id, $bill->bill_id],
            );
            abort_unless($stored, 404, 'ไม่พบไฟล์แนบฉบับปัจจุบัน');
            $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

            return response(base64_decode((string) $stored->encoded, true), 200, [
                'Content-Type' => $stored->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => $disposition."; filename*=UTF-8''".rawurlencode($stored->file_name),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        if ($preparationItem->document_type === 'quotation' && $bill->quotation_document_version_id) {
            $version = $bill->quotationDocumentVersion()->firstOrFail();
            $bytes = $documents->confirmedQuotationVersionPdf($version, $bill);

            return response($bytes, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => $documents->attachmentDisposition($version->filename),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        abort(404, 'Admin ยังไม่ได้อัปโหลดไฟล์สำหรับรายการนี้');
    }
}
