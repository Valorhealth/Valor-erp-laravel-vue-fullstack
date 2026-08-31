<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingRecord;
use App\Services\Authorization\RoleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingRecordController extends Controller
{
    private const STATUS_GROUPS = [
        'approved' => ['รอลูกค้ายืนยัน', 'รอแอดมินส่งออก', 'อนุมัติแล้ว', 'ดำเนินการเสร็จสิ้น'],
        'rejected' => ['รอแก้ไข', 'ไม่อนุมัติ'],
    ];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status_group' => ['nullable', Rule::in(array_keys(self::STATUS_GROUPS))],
            'scope' => ['nullable', Rule::in(['mine', 'all'])],
        ]);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $statusGroup = $filters['status_group'] ?? null;
        $perPage = min(max((int) $request->query('per_page', 20), 5), 100);
        $mustUseOwnRecords = RoleAccess::mustUseOwnBillingRecords(
            $request->user()?->role,
            ($filters['scope'] ?? null) === 'mine',
        );

        $query = BillingRecord::query()
            ->when($mustUseOwnRecords, fn ($query) => $query->where(
                fn ($owned) => $owned->where('creator_user_id', $request->user()->id)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('creator_user_id')->where('created_by', $request->user()->username))
            ))
            ->when($statusGroup !== null, fn ($query) => $query->whereIn('status', self::STATUS_GROUPS[$statusGroup]))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(
                fn ($nested) => $nested
                    ->where('buyer_name', 'ilike', "%{$search}%")
                    ->orWhere('tax_id', 'ilike', "%{$search}%")
                    ->orWhere('created_by', 'ilike', "%{$search}%")
            ));

        $summary = (clone $query)
            ->selectRaw('COUNT(*) AS total_bills, COALESCE(SUM(total_amount), 0) AS total_amount')
            ->first();

        $records = $query
            ->select([
                'bill_id', 'bill_date', 'buyer_name', 'tax_id', 'total_amount',
                'status', 'created_by', 'creator_user_id', 'created_at', 'workflow_type', 'address_note',
                'province', 'district', 'sub_district', 'zipcode', 'shipping_type',
                'shipping_text', 'bill_note', 'sale_discount_total', 'sale_discount_note',
                'bplus_customer_code', 'credit_limit', 'credit_outstanding', 'credit_remain',
                'credit_term', 'requested_credit_term', 'reject_reason', 'tax_info_filled',
                'tax_qr_token', 'document_printed_at', 'document_printed_by',
                'excel_exported_at', 'excel_exported_by', 'tax_invoice_no',
                'submitted_at', 'issued_at',
            ])
            ->with(['items:item_id,bill_id,main_category,sub_category,customer_product_name,unit_type,quantity,unit_price,sale_discount_unit,line_total_before_discount,line_total'])
            ->orderByDesc('bill_id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            ...$records->toArray(),
            'summary' => [
                'total_bills' => (int) ($summary?->total_bills ?? 0),
                'total_amount' => (float) ($summary?->total_amount ?? 0),
            ],
        ]);
    }
}
