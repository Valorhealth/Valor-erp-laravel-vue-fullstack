<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingDocumentPreparationItem;
use App\Models\BillingRecord;
use App\Models\EditLog;
use App\Models\User;
use App\Services\Notifications\InAppNotificationPublisher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                        'preparation_item_id', 'bill_id', 'document_type', 'status', 'admin_note',
                        'requested_at', 'updated_at', 'prepared_at', 'prepared_by',
                    ])
                    ->whereIn('status', self::ACTIVE_STATUSES)
                    ->with('preparedByUser:id,username,first_name,last_name')
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

        return response()->json(['data' => $preparationItem->fresh('preparedByUser:id,username,first_name,last_name')]);
    }
}
