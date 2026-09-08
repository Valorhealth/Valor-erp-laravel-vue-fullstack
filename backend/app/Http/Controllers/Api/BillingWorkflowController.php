<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingRecord;
use App\Models\Quotation;
use App\Services\Authorization\RoleAccess;
use App\Services\Billing\BillingWorkflow;
use App\Services\Billing\BillingCancellationService;
use App\Services\Credit\CustomerCreditResolver;
use App\Services\Documents\DocumentService;
use App\Services\Documents\ElectronicSignatureService;
use App\Services\Notifications\WorkflowEmailNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BillingWorkflowController extends Controller
{
    private const EXPORTABLE_STATUSES = ['รอแอดมินส่งออก', 'อนุมัติแล้ว'];

    public function createFromQuotation(Request $request, Quotation $quotation, BillingWorkflow $workflow, WorkflowEmailNotifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'bill_date' => ['required', 'date'],
            'shipping_type' => ['nullable', 'string', 'max:255'],
            'shipping_text' => ['nullable', 'string', 'max:2000'],
            'bill_note' => ['nullable', 'string', 'max:5000'],
            'save_as_draft' => ['nullable', 'boolean'],
        ]);
        $bill = $workflow->createFromQuotation($request->user(), $quotation, $data);
        if ($bill->status !== BillingWorkflow::DRAFT_STATUS) {
            $notifier->billSubmitted($bill, $request->user());
        }

        return response()->json(['data' => $bill], 201);
    }

    public function createFromPurchaseOrder(Request $request, BillingWorkflow $workflow, WorkflowEmailNotifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'bill_date' => ['required', 'date'],
            'buyer_name' => ['required', 'string', 'max:1000'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'bplus_customer_code' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'sub_district' => ['required', 'string', 'max:255'],
            'zipcode' => ['required', 'string', 'max:20'],
            'address_note' => ['required', 'string', 'max:5000'],
            'shipping_type' => ['required', 'string', 'max:255'],
            'shipping_text' => ['nullable', 'string', 'max:2000'],
            'bill_note' => ['nullable', 'string', 'max:5000'],
            'sale_discount_total' => ['nullable', 'numeric', 'min:0'],
            'sale_discount_note' => ['nullable', 'string', 'max:2000'],
            'requested_credit_term' => ['nullable', 'integer', 'min:0'],
            'customer_po_no' => ['required', 'string', 'max:255'],
            'customer_confirmed_at' => ['required', 'date'],
            'customer_contact_name' => ['nullable', 'string', 'max:500'],
            'customer_contact_channel' => ['nullable', 'string', 'max:255'],
            'payment_terms' => ['nullable', 'string', 'max:5000'],
            'delivery_due_date' => ['nullable', 'date'],
            'delivery_terms' => ['nullable', 'string', 'max:5000'],
            'purchase_order' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,xlsx'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.main_category' => ['required', 'string', 'max:255'],
            'items.*.sub_category' => ['required', 'string', 'max:2000'],
            'items.*.customer_product_name' => ['nullable', 'string', 'max:2000'],
            'items.*.unit_type' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.sale_discount_unit' => ['nullable', 'numeric', 'min:0'],
        ]);
        $bill = $workflow->createFromPurchaseOrder($request->user(), $data, $data['purchase_order']);
        $notifier->billSubmitted($bill, $request->user());

        return response()->json(['data' => $bill], 201);
    }

    public function show(Request $request, BillingRecord $bill): JsonResponse
    {
        $this->authorizeBillRead($request, $bill);

        return response()->json(['data' => $bill->load(['items', 'documents'])]);
    }

    public function updateBySale(Request $request, BillingRecord $bill, BillingWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'bill_date' => ['required', 'date'],
            'buyer_name' => ['required', 'string', 'max:1000'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'address_note' => ['required', 'string', 'max:5000'],
            'province' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'sub_district' => ['required', 'string', 'max:255'],
            'zipcode' => ['required', 'string', 'max:20'],
            'shipping_type' => ['required', 'string', 'max:255'],
            'shipping_text' => ['nullable', 'string', 'max:2000'],
            'bill_note' => ['nullable', 'string', 'max:5000'],
            'sale_discount_total' => ['nullable', 'numeric', 'min:0'],
            'sale_discount_note' => ['nullable', 'string', 'max:2000'],
            'customer_po_no' => ['nullable', 'string', 'max:255'],
            'customer_confirmed_at' => ['nullable', 'date'],
            'customer_contact_name' => ['nullable', 'string', 'max:500'],
            'customer_contact_channel' => ['nullable', 'string', 'max:255'],
            'quotation_valid_until' => ['nullable', 'date'],
            'payment_terms' => ['nullable', 'string', 'max:5000'],
            'delivery_due_date' => ['nullable', 'date'],
            'delivery_terms' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer'],
            'items.*.main_category' => ['required', 'string'],
            'items.*.sub_category' => ['required', 'string'],
            'items.*.customer_product_name' => ['nullable', 'string', 'max:2000'],
            'items.*.unit_type' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.sale_discount_unit' => ['required', 'numeric', 'min:0'],
            'items.*.line_total_before_discount' => ['required', 'numeric', 'min:0'],
            'items.*.line_total' => ['required', 'numeric', 'min:0'],
        ]);
        $bill = $workflow->updateBySale($request->user(), $bill, $data);

        return response()->json(['data' => $bill]);
    }

    public function submitDraft(Request $request, BillingRecord $bill, BillingWorkflow $workflow, WorkflowEmailNotifier $notifier): JsonResponse
    {
        $bill = $workflow->submitDraft($request->user(), $bill);
        $notifier->billSubmitted($bill, $request->user());

        return response()->json(['data' => $bill]);
    }

    public function pendingApproval(CustomerCreditResolver $creditResolver): JsonResponse
    {
        $bills = BillingRecord::query()->with(['items', 'documents'])->where('status', 'รอหัวหน้าอนุมัติ')->orderBy('created_at')->get();
        $this->applyCurrentCreditProfiles($bills, $creditResolver);

        return response()->json(['data' => $bills]);
    }

    public function approve(Request $request, BillingRecord $bill, BillingWorkflow $workflow, WorkflowEmailNotifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'intent' => ['required', 'string', Rule::in([
                ElectronicSignatureService::APPROVAL_INTENT,
                ElectronicSignatureService::PO_DIRECT_APPROVAL_INTENT,
            ])],
        ]);
        $bill = $workflow->approve(
            $request->user(),
            $bill,
            $data['password'],
            $data['intent'],
            $request->ip(),
            $request->userAgent(),
        );
        if ($bill->status === 'รอแอดมินส่งออก') {
            $notifier->billApproved($bill, $request->user());
        } else {
            $notifier->billAwaitingCustomer($bill, $request->user());
        }

        return response()->json(['data' => $bill]);
    }

    public function approvalCertificate(Request $request, BillingRecord $bill, DocumentService $documents): mixed
    {
        $this->authorizeBillRead($request, $bill);
        $signature = $bill->signatures()
            ->where('signature_type', 'internal_quotation_approval')
            ->whereNull('revoked_at')
            ->latest('signed_at')
            ->firstOrFail();
        $bytes = $documents->approvalCertificatePdf($bill, $signature);
        \App\Services\Documents\DocumentDownloadAudit::capture($request, 'approval_certificate', bill: $bill, extra: ['revision_no' => $signature->revision_no]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $documents->attachmentDisposition("Approval_Evidence_Bill_{$bill->bill_id}_R{$signature->revision_no}.pdf"),
        ]);
    }

    public function reject(Request $request, BillingRecord $bill, BillingWorkflow $workflow, WorkflowEmailNotifier $notifier): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);

        $bill = $workflow->reject($request->user(), $bill, $data['reason']);
        $notifier->billRejected($bill, $request->user(), $data['reason']);

        return response()->json(['data' => $bill]);
    }

    public function decline(Request $request, BillingRecord $bill, BillingWorkflow $workflow, WorkflowEmailNotifier $notifier): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);

        $bill = $workflow->decline($request->user(), $bill, $data['reason']);
        $notifier->billDeclined($bill, $request->user(), $data['reason']);

        return response()->json(['data' => $bill]);
    }

    public function readyForExport(): JsonResponse
    {
        return response()->json(['data' => BillingRecord::query()
            ->with(['items', 'creator:id,username,department'])
            ->whereIn('status', self::EXPORTABLE_STATUSES)
            ->orderBy('bill_id')
            ->get()]);
    }

    public function complete(BillingRecord $bill): JsonResponse
    {
        abort_unless(in_array($bill->status, self::EXPORTABLE_STATUSES, true), 422, 'บิลนี้ยังไม่พร้อมปิดงาน');
        $bill->forceFill(['status' => 'ดำเนินการเสร็จสิ้น'])->save();

        return response()->json(['data' => $bill->fresh()]);
    }

    public function completeAll(): JsonResponse
    {
        $count = BillingRecord::query()->whereIn('status', self::EXPORTABLE_STATUSES)->update(['status' => 'ดำเนินการเสร็จสิ้น']);

        return response()->json(['data' => ['updated' => $count]]);
    }

    public function export(Request $request, DocumentService $documents): mixed
    {
        $ids = $request->validate(['bill_ids' => ['nullable', 'array'], 'bill_ids.*' => ['integer']])['bill_ids'] ?? [];
        $bills = BillingRecord::query()
            ->with(['items', 'creator:id,username,department'])
            ->whereIn('status', self::EXPORTABLE_STATUSES)
            ->when($ids !== [], fn ($query) => $query->whereIn('bill_id', $ids))->orderBy('bill_id')->get();
        abort_if($bills->isEmpty(), 422, 'ไม่มีบิลที่พร้อม Export');
        $workbook = $documents->bplusWorkbook($bills);
        $request->attributes->set('document_audit', ['type' => 'bplus_excel', 'documents' => $bills->map(fn ($bill) => ['bill_id' => $bill->bill_id, 'quotation_id' => $bill->ref_qt_id, 'revision_no' => $bill->revision_no])->all()]);
        BillingRecord::query()->whereIn('bill_id', $bills->pluck('bill_id'))->update([
            'excel_exported_at' => now(),
            'excel_exported_by' => $request->user()->username,
        ]);

        return response($workbook, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Bplus_Import_'.now()->format('Y-m-d').'.xlsx"',
        ]);
    }

    public function exportQuotationsPdf(Request $request, DocumentService $documents): mixed
    {
        $ids = $request->validate([
            'bill_ids' => ['required', 'array', 'min:1', 'max:100'],
            'bill_ids.*' => ['required', 'integer', 'distinct'],
        ])['bill_ids'];
        $bills = BillingRecord::query()
            ->with([
                'items', 'signatures', 'documents', 'customerAcceptanceSignatures',
                'quotation.items', 'quotation.creator:id,username,department',
            ])
            ->whereIn('status', self::EXPORTABLE_STATUSES)
            ->whereIn('bill_id', $ids)
            ->orderBy('bill_id')
            ->get();
        abort_if($bills->count() !== count(array_unique($ids)), 422, 'พบบิลที่ไม่อยู่ในสถานะพร้อม Export');
        $withoutQuotation = $bills->filter(fn (BillingRecord $bill): bool => $bill->quotation === null)->pluck('bill_id');
        abort_if($withoutQuotation->isNotEmpty(), 422, 'Bill #'.$withoutQuotation->implode(', #').' เปิดจาก PO โดยตรง จึงไม่มีใบเสนอราคาให้ Export');

        $bytes = $documents->quotationBatchPdf($bills);
        $request->attributes->set('document_audit', ['type' => 'quotation_batch', 'documents' => $bills->map(fn ($bill) => ['bill_id' => $bill->bill_id, 'quotation_id' => $bill->ref_qt_id, 'quotation_no' => $bill->quotation->qt_no, 'revision_no' => $bill->revision_no])->all()]);
        Quotation::query()->whereIn('qt_id', $bills->pluck('ref_qt_id'))->update([
            'quotation_printed_at' => now(),
            'quotation_printed_by' => $request->user()->username,
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $documents->attachmentDisposition('Quotations_'.now()->format('Y-m-d_His').'.pdf'),
        ]);
    }

    public function pdf(Request $request, BillingRecord $bill, DocumentService $documents): mixed
    {
        $this->authorizeBillRead($request, $bill);
        $copy = $request->boolean('copy');
        $canceled = $request->boolean('canceled');
        if (! $canceled && in_array($bill->status, [BillingWorkflow::DRAFT_STATUS, 'รออนุมัติ', 'รอหัวหน้าอนุมัติ', 'รอแก้ไข'], true)) {
            abort(422, 'บิลนี้ยังไม่ผ่านการอนุมัติ จึงยังออกเลขใบกำกับภาษีไม่ได้');
        }
        if (! $canceled && $bill->bill_date?->isFuture()) {
            abort(422, 'บิลลงวันที่อนาคตได้ใน Draft แต่ยังออกเลขใบกำกับภาษีก่อนถึงวันที่บิลไม่ได้');
        }
        if ($canceled && ! in_array($bill->status, ['ยกเลิกบิล', 'ไม่อนุมัติ'], true)) {
            abort(422, 'สร้างเอกสารยกเลิกได้เฉพาะบิลที่ถูกยกเลิกหรือไม่อนุมัติ');
        }
        if ($canceled && ! $bill->tax_invoice_no) {
            abort(422, 'บิลนี้ยังไม่เคยออกเลขใบกำกับภาษี จึงไม่มีเอกสารต้นฉบับให้ยกเลิก');
        }
        $bytes = $documents->billPdf($bill, $copy, $canceled);
        $bill->forceFill(['document_printed_at' => now(), 'document_printed_by' => $request->user()->username])->save();
        $filename = $documents->billDownloadName($bill, $copy, $canceled);
        \App\Services\Documents\DocumentDownloadAudit::capture($request, $canceled ? 'bill_canceled' : ($copy ? 'bill_copy' : 'bill_original'), bill: $bill, extra: ['tax_invoice_no' => $bill->tax_invoice_no]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $documents->attachmentDisposition($filename),
        ]);
    }

    public function adminUpdate(
        Request $request,
        BillingRecord $bill,
        CustomerCreditResolver $creditResolver,
    ): JsonResponse {
        $billFields = [
            'bill_date', 'buyer_name', 'tax_id', 'address_note', 'province', 'district',
            'sub_district', 'zipcode', 'shipping_type', 'shipping_text', 'bill_note', 'status',
            'sale_discount_total', 'sale_discount_note', 'admin_branch', 'admin_dept', 'admin_project',
            'admin_discount_final', 'bplus_customer_code', 'bplus_salesman_code', 'bplus_sale_type_code',
            'warehouse_code', 'credit_limit', 'credit_outstanding', 'credit_remain', 'credit_term',
            'requested_credit_term', 'reject_reason',
            'customer_po_no', 'customer_confirmed_at', 'customer_contact_name', 'customer_contact_channel',
            'quotation_valid_until', 'payment_terms', 'delivery_due_date', 'delivery_terms',
        ];
        $data = $request->validate([
            'bill' => ['required', 'array'],
            'bill.bill_date' => ['nullable', 'date'],
            'bill.buyer_name' => ['nullable', 'string', 'max:1000'],
            'bill.tax_id' => ['nullable', 'string', 'max:30'],
            'bill.address_note' => ['nullable', 'string', 'max:5000'],
            'bill.province' => ['nullable', 'string', 'max:255'],
            'bill.district' => ['nullable', 'string', 'max:255'],
            'bill.sub_district' => ['nullable', 'string', 'max:255'],
            'bill.zipcode' => ['nullable', 'string', 'max:20'],
            'bill.shipping_type' => ['nullable', 'string', 'max:255'],
            'bill.shipping_text' => ['nullable', 'string', 'max:2000'],
            'bill.bill_note' => ['nullable', 'string', 'max:5000'],
            'bill.status' => ['nullable', Rule::in(BillingWorkflow::ALL_STATUSES)],
            'bill.sale_discount_total' => ['nullable', 'numeric', 'min:0'],
            'bill.sale_discount_note' => ['nullable', 'string', 'max:2000'],
            'bill.admin_branch' => ['nullable', 'string', 'max:255'],
            'bill.admin_dept' => ['nullable', 'string', 'max:255'],
            'bill.admin_project' => ['nullable', 'string', 'max:255'],
            'bill.admin_discount_final' => ['nullable', 'numeric', 'min:0'],
            'bill.bplus_customer_code' => ['nullable', 'string', 'max:255'],
            'bill.bplus_salesman_code' => ['nullable', 'string', 'max:255'],
            'bill.bplus_sale_type_code' => ['nullable', Rule::in(['สินเชื่อ', 'เงินสด', 'ผ่อนชำระ'])],
            'bill.warehouse_code' => ['nullable', 'string', 'max:100'],
            'bill.credit_limit' => ['nullable', 'numeric', 'min:0'],
            'bill.credit_outstanding' => ['nullable', 'numeric', 'min:0'],
            'bill.credit_remain' => ['nullable', 'numeric', 'min:0'],
            'bill.credit_term' => ['nullable', 'integer', 'min:0'],
            'bill.requested_credit_term' => ['nullable', 'integer', 'min:0'],
            'bill.reject_reason' => ['nullable', 'string', 'max:5000'],
            'bill.customer_po_no' => ['nullable', 'string', 'max:255'],
            'bill.customer_confirmed_at' => ['nullable', 'date'],
            'bill.customer_contact_name' => ['nullable', 'string', 'max:500'],
            'bill.customer_contact_channel' => ['nullable', 'string', 'max:255'],
            'bill.quotation_valid_until' => ['nullable', 'date'],
            'bill.payment_terms' => ['nullable', 'string', 'max:5000'],
            'bill.delivery_due_date' => ['nullable', 'date'],
            'bill.delivery_terms' => ['nullable', 'string', 'max:5000'],
            'items' => ['nullable', 'array'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.main_category' => ['required', 'string', 'max:255'],
            'items.*.sub_category' => ['required', 'string', 'max:2000'],
            'items.*.unit_type' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.sale_discount_unit' => ['nullable', 'numeric', 'min:0'],
            'items.*.admin_lot' => ['nullable', 'string', 'max:1000'],
            'items.*.admin_serial' => ['nullable', 'string', 'max:1000'],
            'items.*.admin_storage' => ['nullable', 'string', 'max:255'],
            'items.*.admin_free_qty' => ['nullable', 'integer', 'min:0'],
            'items.*.admin_disocount_unit' => ['nullable', 'numeric', 'min:0'],
            'items.*.admin_weight_unit' => ['nullable', 'numeric', 'min:0'],
        ]);
        abort_if(($data['bill']['status'] ?? null) === 'ยกเลิกบิล', 422, 'กรุณาใช้ปุ่ม “ยกเลิกบิล” และระบุเหตุผล เพื่อเก็บหลักฐานให้ครบ');
        $result = DB::transaction(function () use ($bill, $data, $billFields, $creditResolver): BillingRecord {
            $bill = BillingRecord::query()->lockForUpdate()->with('items')->findOrFail($bill->bill_id);
            $bill->fill(Arr::only($data['bill'], $billFields));
            foreach ($data['items'] ?? [] as $item) {
                $ownedItem = $bill->items->firstWhere('item_id', (int) $item['item_id']);
                if (! $ownedItem) {
                    abort(422, "รายการสินค้า #{$item['item_id']} ไม่ได้อยู่ในบิลนี้");
                }
                $beforeDiscount = round((int) $item['quantity'] * (float) $item['unit_price'], 2);
                $discount = min((float) ($item['sale_discount_unit'] ?? 0), $beforeDiscount);
                $updates = [
                    ...Arr::only($item, [
                        'main_category', 'sub_category', 'unit_type', 'quantity', 'unit_price', 'sale_discount_unit',
                        'admin_lot', 'admin_serial', 'admin_storage',
                        'admin_free_qty', 'admin_disocount_unit', 'admin_weight_unit',
                    ]),
                    'sale_discount_unit' => $discount,
                    'line_total_before_discount' => $beforeDiscount,
                    'line_total' => round($beforeDiscount - $discount, 2),
                ];
                $ownedItem->fill($updates)->save();
            }
            $itemsTotal = (float) $bill->items()->sum('line_total');
            $tailDiscount = min((float) ($bill->sale_discount_total ?? 0), $itemsTotal);
            $bill->forceFill([
                'sale_discount_total' => $tailDiscount,
                'total_amount' => round(max($itemsTotal - $tailDiscount, 0), 2),
            ])->save();

            if (array_key_exists('credit_outstanding', $data['bill'])
                || array_key_exists('credit_limit', $data['bill'])
                || array_key_exists('credit_term', $data['bill'])) {
                $limit = (float) ($bill->credit_limit ?? 0);
                $outstanding = (float) ($bill->credit_outstanding ?? 0);
                $bill->forceFill(['credit_remain' => max($limit - $outstanding, 0)])->save();
                $creditResolver->updateCurrentCredit(
                    $bill->bplus_customer_code,
                    $bill->buyer_name,
                    $limit,
                    (int) ($bill->credit_term ?? 0),
                    $outstanding,
                );
            }

            return $bill->fresh('items');
        });

        return response()->json(['data' => $result]);
    }

    public function adminUpdateStatus(Request $request, BillingRecord $bill): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(BillingWorkflow::ALL_STATUSES)],
        ]);
        abort_if($data['status'] === 'ยกเลิกบิล', 422, 'กรุณาใช้ปุ่ม “ยกเลิกบิล” และระบุเหตุผล เพื่อเก็บหลักฐานให้ครบ');

        $result = DB::transaction(function () use ($bill, $data): BillingRecord {
            $lockedBill = BillingRecord::query()->lockForUpdate()->findOrFail($bill->bill_id);
            $lockedBill->forceFill(['status' => $data['status']])->save();

            return $lockedBill->fresh('items');
        });

        return response()->json(['data' => $result]);
    }

    public function cancel(Request $request, BillingRecord $bill, BillingCancellationService $cancellation): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:5000']]);

        return response()->json(['data' => $cancellation->cancel($request->user(), $bill, $data['reason'])]);
    }

    private function authorizeBillRead(Request $request, BillingRecord $bill): void
    {
        if (RoleAccess::mustUseOwnBillingRecords($request->user()->role)
            && (int) ($bill->creator_user_id ?? 0) !== (int) $request->user()->id
            && ! ($bill->creator_user_id === null && $bill->created_by === $request->user()->username)) {
            abort(403, 'คุณไม่มีสิทธิ์เข้าถึงบิลนี้');
        }
    }

    private function applyCurrentCreditProfiles(iterable $bills, CustomerCreditResolver $creditResolver): void
    {
        foreach ($bills as $bill) {
            $profile = $creditResolver->resolve($bill->bplus_customer_code, $bill->buyer_name);
            if (! $profile) {
                continue;
            }
            $limit = (float) $profile->credit_limit;
            $outstanding = (float) ($profile->credit_outstanding ?? 0);
            $bill->setAttribute('credit_limit', $limit);
            $bill->setAttribute('credit_term', (int) $profile->credit_term);
            $bill->setAttribute('credit_outstanding', $outstanding);
            $bill->setAttribute('credit_remain', max($limit - $outstanding, 0));
        }
    }
}
