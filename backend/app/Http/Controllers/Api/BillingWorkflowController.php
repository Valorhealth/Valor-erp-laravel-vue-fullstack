<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingRecord;
use App\Models\Quotation;
use App\Services\Authorization\RoleAccess;
use App\Services\Billing\BillingWorkflow;
use App\Services\Credit\CustomerCreditResolver;
use App\Services\Documents\DocumentService;
use App\Services\Notifications\WorkflowEmailNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BillingWorkflowController extends Controller
{
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

    public function show(Request $request, BillingRecord $bill): JsonResponse
    {
        $this->authorizeBillRead($request, $bill);

        return response()->json(['data' => $bill->load('items')]);
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
        $bills = BillingRecord::query()->with('items')->where('status', 'รอหัวหน้าอนุมัติ')->orderBy('created_at')->get();
        $this->applyCurrentCreditProfiles($bills, $creditResolver);

        return response()->json(['data' => $bills]);
    }

    public function approve(Request $request, BillingRecord $bill, BillingWorkflow $workflow, WorkflowEmailNotifier $notifier): JsonResponse
    {
        $bill = $workflow->approve($request->user(), $bill);
        $notifier->billApproved($bill, $request->user());

        return response()->json(['data' => $bill]);
    }

    public function reject(Request $request, BillingRecord $bill, BillingWorkflow $workflow, WorkflowEmailNotifier $notifier): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);

        $bill = $workflow->reject($request->user(), $bill, $data['reason']);
        $notifier->billRejected($bill, $request->user(), $data['reason']);

        return response()->json(['data' => $bill]);
    }

    public function adminQueue(CustomerCreditResolver $creditResolver): JsonResponse
    {
        $bills = BillingRecord::query()->with('items')->where('status', 'รอแอดมินตรวจสอบ')->orderBy('created_at')->get();
        $this->applyCurrentCreditProfiles($bills, $creditResolver);

        return response()->json(['data' => $bills]);
    }

    public function prepare(Request $request, BillingRecord $bill, BillingWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'admin_branch' => ['required', Rule::in(['VH000', 'VH001', 'VH002', 'VH003', 'VH004', 'VH005'])],
            'admin_dept' => ['required', 'string', 'max:255'],
            'admin_project' => ['required', 'string', 'max:255'],
            'admin_discount_final' => ['nullable', 'numeric', 'min:0'],
            'bplus_salesman_code' => ['required', 'string', 'max:255'],
            'bplus_sale_type_code' => ['required', Rule::in(['สินเชื่อ', 'เงินสด', 'ผ่อนชำระ'])],
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'credit_outstanding' => ['required', 'numeric', 'min:0'],
            'credit_term' => ['required', 'integer', 'min:0'],
            'requested_credit_term' => ['required', 'integer', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.admin_lot' => ['nullable', 'string', 'max:1000'],
            'items.*.admin_serial' => ['nullable', 'string', 'max:1000'],
            'items.*.admin_storage' => ['nullable', 'string', 'max:255'],
            'items.*.admin_free_qty' => ['nullable', 'integer', 'min:0'],
            'items.*.admin_disocount_unit' => ['nullable', 'numeric', 'min:0'],
            'items.*.admin_weight_unit' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json(['data' => $workflow->prepareForExport($request->user(), $bill, $data)]);
    }

    public function readyForExport(): JsonResponse
    {
        return response()->json(['data' => BillingRecord::query()->with('items')->whereIn('status', ['เตรียมส่งออก', 'อนุมัติแล้ว'])->orderBy('bill_id')->get()]);
    }

    public function complete(BillingRecord $bill): JsonResponse
    {
        abort_unless(in_array($bill->status, ['เตรียมส่งออก', 'อนุมัติแล้ว'], true), 422, 'บิลนี้ยังไม่พร้อมปิดงาน');
        $bill->forceFill(['status' => 'ดำเนินการเสร็จสิ้น'])->save();

        return response()->json(['data' => $bill->fresh()]);
    }

    public function completeAll(): JsonResponse
    {
        $count = BillingRecord::query()->whereIn('status', ['เตรียมส่งออก', 'อนุมัติแล้ว'])->update(['status' => 'ดำเนินการเสร็จสิ้น']);

        return response()->json(['data' => ['updated' => $count]]);
    }

    public function export(Request $request, DocumentService $documents): mixed
    {
        $ids = $request->validate(['bill_ids' => ['nullable', 'array'], 'bill_ids.*' => ['integer']])['bill_ids'] ?? [];
        $bills = BillingRecord::query()->with('items')->whereIn('status', ['เตรียมส่งออก', 'อนุมัติแล้ว'])
            ->when($ids !== [], fn ($query) => $query->whereIn('bill_id', $ids))->orderBy('bill_id')->get();
        abort_if($bills->isEmpty(), 422, 'ไม่มีบิลที่พร้อม Export');
        BillingRecord::query()->whereIn('bill_id', $bills->pluck('bill_id'))->update([
            'excel_exported_at' => now(),
            'excel_exported_by' => $request->user()->username,
        ]);

        return response($documents->bplusWorkbook($bills), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Bplus_Import_'.now()->format('Y-m-d').'.xlsx"',
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

        $result = DB::transaction(function () use ($bill, $data): BillingRecord {
            $lockedBill = BillingRecord::query()->lockForUpdate()->findOrFail($bill->bill_id);
            $lockedBill->forceFill(['status' => $data['status']])->save();

            return $lockedBill->fresh('items');
        });

        return response()->json(['data' => $result]);
    }

    public function destroy(BillingRecord $bill): JsonResponse
    {
        DB::transaction(function () use ($bill): void {
            $reference = $bill->ref_qt_id;
            $bill->items()->delete();
            $bill->delete();
            if ($reference) {
                Quotation::query()->find($reference)?->items()->delete();
                Quotation::query()->whereKey($reference)->delete();
            }
        });

        return response()->json(status: 204);
    }

    private function authorizeBillRead(Request $request, BillingRecord $bill): void
    {
        if (RoleAccess::mustUseOwnBillingRecords($request->user()->role) && $bill->created_by !== $request->user()->username) {
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
