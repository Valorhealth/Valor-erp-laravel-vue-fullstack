<?php

namespace App\Services\Billing;

use App\Models\BillingRecord;
use App\Models\BillItem;
use App\Models\EditLog;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Credit\CustomerCreditResolver;
use App\Services\Documents\ElectronicSignatureService;
use App\Services\Documents\BillingDocumentStorage;
use App\Services\Quotations\QuotationRevisionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BillingWorkflow
{
    public const DRAFT_STATUS = 'ฉบับร่าง';

    public const ALL_STATUSES = [
        self::DRAFT_STATUS,
        'รออนุมัติ',
        'รอหัวหน้าอนุมัติ',
        'รอลูกค้ายืนยัน',
        'รอแอดมินส่งออก',
        'รอแก้ไข',
        'อนุมัติแล้ว',
        'ดำเนินการเสร็จสิ้น',
        'ไม่อนุมัติ',
        'ยกเลิกบิล',
    ];

    public const SALE_EDITABLE_STATUSES = [self::DRAFT_STATUS, 'รอแก้ไข'];

    public function __construct(
        private readonly CustomerCreditResolver $creditResolver,
        private readonly QuotationRevisionService $revisions,
        private readonly ElectronicSignatureService $electronicSignatures,
        private readonly BillingDocumentStorage $documentStorage,
    ) {}

    /** @param array<string, mixed> $payload */
    public function createFromQuotation(User $user, Quotation $quotation, array $payload): BillingRecord
    {
        return DB::transaction(function () use ($user, $quotation, $payload): BillingRecord {
            $quotation = Quotation::query()->lockForUpdate()->with('items')->findOrFail($quotation->qt_id);

            if (! $this->isOwnedBy($quotation, $user)) {
                abort(403, 'คุณไม่มีสิทธิ์ใช้ใบเสนอราคานี้');
            }

            $isDraft = (bool) ($payload['save_as_draft'] ?? false);

            if (BillingRecord::query()->where('ref_qt_id', $quotation->qt_id)->exists()) {
                throw ValidationException::withMessages(['quotation' => 'ใบเสนอราคานี้ถูกสร้างเป็นบิลแล้ว']);
            }

            if (! $isDraft && $quotation->workflow_type === 'credit_first' && $quotation->status !== 'เปิดวงเงินแล้ว') {
                throw ValidationException::withMessages(['quotation' => 'ใบเสนอราคานี้ต้องรอ Admin เปิดวงเงินก่อนจึงจะสร้างบิลได้']);
            }

            if ($quotation->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'ใบเสนอราคาไม่มีรายการสินค้า']);
            }

            $creditProfile = $this->creditResolver->resolve($quotation->bplus_customer_code, $quotation->buyer_name);
            $creditLimit = (float) ($creditProfile?->credit_limit ?? $quotation->credit_limit ?? 0);
            $creditTerm = (int) ($creditProfile?->credit_term ?? $quotation->credit_term ?? 0);
            $creditOutstanding = (float) ($creditProfile?->credit_outstanding ?? 0);
            // Credit rules are decision support only. A commercial quotation must
            // still receive an identifiable human approval/e-signature.
            $initialStatus = 'รอหัวหน้าอนุมัติ';
            $autoReason = null;

            $bill = BillingRecord::query()->create([
                ...Arr::only($quotation->getAttributes(), [
                    'buyer_name', 'tax_id', 'total_amount', 'province', 'district', 'sub_district',
                    'zipcode', 'address_note', 'shipping_type', 'shipping_text', 'bill_note',
                    'bplus_customer_code', 'workflow_type', 'sale_discount_total', 'sale_discount_note',
                    'credit_limit', 'credit_term', 'requested_credit_term',
                    'customer_po_no', 'customer_confirmed_at', 'customer_contact_name', 'customer_contact_channel',
                    'quotation_valid_until', 'payment_terms', 'delivery_due_date', 'delivery_terms', 'revision_no',
                ]),
                ...Arr::only($payload, ['bill_date', 'shipping_type', 'shipping_text', 'bill_note']),
                'created_by' => $user->username,
                'creator_user_id' => $user->id,
                'created_at' => now(),
                'status' => $isDraft ? self::DRAFT_STATUS : $initialStatus,
                'req_tax_invoice' => true,
                'ref_qt_id' => $quotation->qt_id,
                'auto_approved_reason' => $isDraft ? null : $autoReason,
                'submitted_at' => $isDraft ? null : now(),
                'credit_outstanding' => $creditOutstanding,
                'credit_remain' => max($creditLimit - $creditOutstanding, 0),
                'credit_limit' => $creditLimit,
                'credit_term' => $creditTerm,
                'tax_qr_token' => Str::random(48),
                'customer_acceptance_token' => Str::random(64),
                'customer_acceptance_expires_at' => now()->addDays(30),
            ]);

            foreach ($quotation->items as $item) {
                $bill->items()->create(Arr::only($item->getAttributes(), [
                    'main_category', 'sub_category', 'unit_type', 'quantity', 'unit_price',
                    'customer_product_name', 'sale_discount_unit', 'line_total_before_discount', 'line_total',
                ]));
            }

            $this->revisions->record($bill, $user->username, 'สร้างฉบับร่างเริ่มต้น');

            return $bill->load(['items', 'documents']);
        });
    }

    /** @param array<string, mixed> $payload */
    public function createFromPurchaseOrder(User $user, array $payload, \Illuminate\Http\UploadedFile $purchaseOrder): BillingRecord
    {
        return DB::transaction(function () use ($user, $payload, $purchaseOrder): BillingRecord {
            $items = collect($payload['items'])->map(function (array $item): array {
                $before = round((int) $item['quantity'] * (float) $item['unit_price'], 2);
                $discount = min((float) ($item['sale_discount_unit'] ?? 0), $before);

                return [
                    ...Arr::only($item, ['main_category', 'sub_category', 'customer_product_name', 'unit_type', 'quantity', 'unit_price']),
                    'sale_discount_unit' => $discount,
                    'line_total_before_discount' => $before,
                    'line_total' => round($before - $discount, 2),
                ];
            });
            $gross = (float) $items->sum('line_total');
            $tailDiscount = min((float) ($payload['sale_discount_total'] ?? 0), $gross);
            $credit = $this->creditResolver->resolve($payload['bplus_customer_code'], $payload['buyer_name']);
            $creditLimit = (float) ($credit?->credit_limit ?? 0);
            $creditOutstanding = (float) ($credit?->credit_outstanding ?? 0);

            $bill = BillingRecord::query()->create([
                ...Arr::only($payload, [
                    'bill_date', 'buyer_name', 'tax_id', 'province', 'district', 'sub_district', 'zipcode',
                    'address_note', 'shipping_type', 'shipping_text', 'bill_note', 'bplus_customer_code',
                    'sale_discount_note', 'requested_credit_term', 'customer_po_no', 'customer_confirmed_at',
                    'customer_contact_name', 'customer_contact_channel', 'payment_terms', 'delivery_due_date', 'delivery_terms',
                ]),
                'total_amount' => max(round($gross - $tailDiscount, 2), 0),
                'sale_discount_total' => $tailDiscount,
                'workflow_type' => 'customer_po_direct',
                'credit_limit' => $creditLimit,
                'credit_outstanding' => $creditOutstanding,
                'credit_remain' => max($creditLimit - $creditOutstanding, 0),
                'credit_term' => (int) ($credit?->credit_term ?? 0),
                'requested_credit_term' => $payload['requested_credit_term'] ?? $credit?->credit_term ?? 0,
                'created_by' => $user->username,
                'creator_user_id' => $user->id,
                'created_at' => now(),
                'submitted_at' => now(),
                'status' => 'รอหัวหน้าอนุมัติ',
                'req_tax_invoice' => true,
                'ref_qt_id' => null,
                'tax_qr_token' => Str::random(48),
            ]);

            foreach ($items as $item) {
                $bill->items()->create($item);
            }
            $this->documentStorage->storeUpload($bill, 'customer_po', $purchaseOrder, $user->username);
            EditLog::query()->create([
                'bill_id' => $bill->bill_id,
                'edited_by' => $user->username,
                'edit_time' => now(),
                'edit_summary' => 'สร้างรายการเปิดบิลจาก PO โดยตรง (ไม่ออกเลขใบเสนอราคา)',
            ]);

            return $bill->fresh(['items', 'documents']);
        });
    }

    /** @param array<string, mixed> $payload */
    public function updateBySale(User $user, BillingRecord $bill, array $payload): BillingRecord
    {
        return DB::transaction(function () use ($user, $bill, $payload): BillingRecord {
            $bill = BillingRecord::query()->lockForUpdate()->with('items')->findOrFail($bill->bill_id);
            if (! $this->isOwnedBy($bill, $user)) {
                abort(403, 'คุณไม่มีสิทธิ์แก้ไขบิลนี้');
            }
            if (! in_array($bill->status, self::SALE_EDITABLE_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => "บิลสถานะ {$bill->status} ไม่สามารถแก้ไขได้"]);
            }

            $before = $bill->only([
                'bill_date', 'buyer_name', 'tax_id', 'total_amount', 'address_note', 'province',
                'district', 'sub_district', 'zipcode', 'shipping_type', 'shipping_text', 'bill_note',
                'sale_discount_total', 'sale_discount_note',
                'customer_po_no', 'customer_confirmed_at', 'customer_contact_name', 'customer_contact_channel',
                'quotation_valid_until', 'payment_terms', 'delivery_due_date', 'delivery_terms',
            ]);
            $billFields = Arr::only($payload, array_keys($before));
            unset($billFields['total_amount']);
            $bill->fill($billFields);
            if ($bill->status === 'รอแก้ไข') {
                $bill->status = 'รอหัวหน้าอนุมัติ';
                $bill->reject_reason = null;
                $bill->submitted_at = now();
            }
            $bill->save();

            $isDraft = $bill->status === self::DRAFT_STATUS;
            if ($isDraft) {
                $submittedItemIds = collect($payload['items'] ?? [])
                    ->pluck('item_id')
                    ->filter()
                    ->map(fn (mixed $itemId): int => (int) $itemId);
                $bill->items()
                    ->when($submittedItemIds->isNotEmpty(), fn ($query) => $query->whereNotIn('item_id', $submittedItemIds))
                    ->delete();
            }

            $itemsTotal = 0.0;
            foreach ($payload['items'] ?? [] as $itemData) {
                $itemId = (int) ($itemData['item_id'] ?? 0);
                $item = $itemId > 0 ? $bill->items->firstWhere('item_id', $itemId) : null;
                if ($itemId > 0 && ! $item) {
                    throw ValidationException::withMessages(['items' => "รายการสินค้า #{$itemId} ไม่ได้อยู่ในบิลนี้"]);
                }
                if (! $item && ! $isDraft) {
                    throw ValidationException::withMessages(['items' => 'เพิ่มรายการสินค้าใหม่ได้เฉพาะบิล Draft']);
                }

                $beforeDiscount = round((int) $itemData['quantity'] * (float) $itemData['unit_price'], 2);
                $discount = min((float) ($itemData['sale_discount_unit'] ?? 0), $beforeDiscount);
                $lineTotal = round($beforeDiscount - $discount, 2);
                $itemValues = [
                    ...Arr::only($itemData, ['main_category', 'sub_category', 'customer_product_name', 'unit_type', 'quantity', 'unit_price']),
                    'sale_discount_unit' => $discount,
                    'line_total_before_discount' => $beforeDiscount,
                    'line_total' => $lineTotal,
                ];
                $item
                    ? $item->fill($itemValues)->save()
                    : $bill->items()->create($itemValues);
                $itemsTotal += $lineTotal;
            }
            $bill->forceFill([
                'sale_discount_total' => min((float) ($payload['sale_discount_total'] ?? 0), $itemsTotal),
                'total_amount' => max(round($itemsTotal - (float) ($payload['sale_discount_total'] ?? 0), 2), 0),
            ])->save();

            $changes = [];
            foreach ($billFields as $key => $value) {
                if ((string) ($before[$key] ?? '') !== (string) $value) {
                    $changes[] = "{$key}: '{$before[$key]}' -> '{$value}'";
                }
            }
            if ((float) ($before['total_amount'] ?? 0) !== (float) $bill->total_amount) {
                $changes[] = "total_amount: '{$before['total_amount']}' -> '{$bill->total_amount}'";
            }
            if ($changes !== [] || ($payload['items'] ?? []) !== []) {
                EditLog::query()->create([
                    'bill_id' => $bill->bill_id,
                    'edited_by' => $user->username,
                    'edit_time' => now(),
                    'edit_summary' => implode(' | ', $changes ?: ['แก้ไขรายการสินค้า']),
                ]);
            }

            $this->revisions->record($bill, $user->username, implode(' | ', $changes ?: ['แก้ไขรายการสินค้า']), true);

            return $bill->fresh(['items', 'documents']);
        });
    }

    public function submitDraft(User $user, BillingRecord $bill): BillingRecord
    {
        return DB::transaction(function () use ($user, $bill): BillingRecord {
            $bill = BillingRecord::query()->lockForUpdate()->with('items')->findOrFail($bill->bill_id);
            if (! $this->isOwnedBy($bill, $user)) {
                abort(403, 'คุณไม่มีสิทธิ์ส่งบิลนี้');
            }
            if ($bill->status !== self::DRAFT_STATUS) {
                throw ValidationException::withMessages(['status' => 'ส่งได้เฉพาะบิลสถานะฉบับร่าง']);
            }
            if ($bill->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Draft ต้องมีรายการสินค้าอย่างน้อย 1 รายการ']);
            }

            $required = [
                'bill_date' => 'วันที่บิล',
                'buyer_name' => 'ชื่อลูกค้า',
                'address_note' => 'ที่อยู่',
                'province' => 'จังหวัด',
                'district' => 'อำเภอ/เขต',
                'sub_district' => 'ตำบล/แขวง',
                'shipping_type' => 'ประเภทการจัดส่ง',
            ];
            $missing = collect($required)
                ->filter(fn (string $label, string $field): bool => blank($bill->getAttribute($field)))
                ->values()
                ->all();
            if ($missing !== []) {
                throw ValidationException::withMessages(['draft' => 'กรุณากรอกข้อมูลให้ครบ: '.implode(', ', $missing)]);
            }

            $quotationStatus = $bill->ref_qt_id
                ? Quotation::query()->whereKey($bill->ref_qt_id)->value('status')
                : null;
            if ($bill->workflow_type === 'credit_first' && $quotationStatus !== 'เปิดวงเงินแล้ว') {
                throw ValidationException::withMessages([
                    'credit' => 'ใบเสนอราคานี้ต้องรอ Admin เปิดวงเงินก่อนจึงจะส่งอนุมัติได้',
                ]);
            }
            $creditLimit = (float) ($bill->credit_limit ?? 0);
            $creditPrechecked = $bill->workflow_type === 'credit_first'
                && $quotationStatus === 'เปิดวงเงินแล้ว'
                && $creditLimit > 0
                && (float) $bill->total_amount <= $creditLimit;
            $autoReason = $creditPrechecked ? 'ผ่านเงื่อนไขวงเงินเบื้องต้น แต่ยังต้องมีผู้อนุมัติลงนาม' : null;

            $bill->forceFill([
                'status' => 'รอหัวหน้าอนุมัติ',
                'submitted_at' => now(),
                'auto_approved_reason' => $autoReason,
                'reject_reason' => null,
                'customer_acceptance_token' => $bill->ref_qt_id ? Str::random(64) : null,
                'customer_acceptance_expires_at' => $bill->ref_qt_id ? now()->addDays(30) : null,
                'customer_acceptance_used_at' => null,
            ])->save();
            EditLog::query()->create([
                'bill_id' => $bill->bill_id,
                'edited_by' => $user->username,
                'edit_time' => now(),
                'edit_summary' => 'ส่ง Draft เข้าสู่ขั้นตอนอนุมัติ',
            ]);

            return $bill->fresh('items');
        });
    }

    public function approve(
        User $user,
        BillingRecord $bill,
        string $password,
        string $intent,
        ?string $ipAddress,
        ?string $userAgent,
    ): BillingRecord
    {
        return DB::transaction(function () use ($user, $bill, $password, $intent, $ipAddress, $userAgent): BillingRecord {
            $bill = BillingRecord::query()->lockForUpdate()->with('items')->findOrFail($bill->bill_id);
            if ($bill->status !== 'รอหัวหน้าอนุมัติ') {
                throw ValidationException::withMessages(['status' => "สถานะปัจจุบันคือ {$bill->status} ไม่ใช่ รอหัวหน้าอนุมัติ"]);
            }
            $this->electronicSignatures->signInternalApproval(
                $user,
                $bill,
                $password,
                $intent,
                $ipAddress,
                $userAgent,
            );
            $hasCustomerEvidence = DB::table('billing_documents')
                ->where('bill_id', $bill->bill_id)
                ->where('is_current', true)
                ->whereIn('document_type', ['customer_po', 'signed_quotation'])
                ->exists();
            $bill->forceFill([
                'status' => $hasCustomerEvidence ? 'รอแอดมินส่งออก' : 'รอลูกค้ายืนยัน',
                'reject_reason' => null,
            ])->save();

            return $bill->fresh(['items', 'documents']);
        });
    }

    public function confirmCustomerEvidence(User $user, BillingRecord $bill): BillingRecord
    {
        return DB::transaction(function () use ($user, $bill): BillingRecord {
            $bill = BillingRecord::query()->lockForUpdate()->findOrFail($bill->bill_id);
            if (! $this->isOwnedBy($bill, $user)) {
                abort(403, 'คุณไม่มีสิทธิ์ยืนยันหลักฐานลูกค้าของรายการนี้');
            }
            if ($bill->status !== 'รอลูกค้ายืนยัน') {
                return $bill->fresh(['items', 'documents']);
            }
            $hasEvidence = DB::table('billing_documents')
                ->where('bill_id', $bill->bill_id)
                ->where('is_current', true)
                ->whereIn('document_type', ['customer_po', 'signed_quotation'])
                ->exists();
            if (! $hasEvidence) {
                throw ValidationException::withMessages(['evidence' => 'กรุณาแนบ PO หรือใบเสนอราคาที่ลูกค้าลงชื่อก่อนส่งต่อ Admin']);
            }
            $bill->forceFill([
                'status' => 'รอแอดมินส่งออก',
                'customer_confirmed_at' => $bill->customer_confirmed_at ?: today(),
            ])->save();
            EditLog::query()->create([
                'bill_id' => $bill->bill_id,
                'edited_by' => $user->username,
                'edit_time' => now(),
                'edit_summary' => 'แนบหลักฐานยืนยันจากลูกค้าและส่งต่อ Admin',
            ]);

            return $bill->fresh(['items', 'documents']);
        });
    }

    public function reject(User $user, BillingRecord $bill, string $reason): BillingRecord
    {
        return $this->transition($bill, 'รอหัวหน้าอนุมัติ', [
            'status' => 'รอแก้ไข',
            'reject_reason' => $reason,
        ]);
    }

    public function decline(User $user, BillingRecord $bill, string $reason): BillingRecord
    {
        return $this->transition($bill, 'รอหัวหน้าอนุมัติ', [
            'status' => 'ไม่อนุมัติ',
            'reject_reason' => $reason,
        ]);
    }

    /** @param array<string, mixed> $updates */
    private function transition(BillingRecord $bill, string $expected, array $updates): BillingRecord
    {
        return DB::transaction(function () use ($bill, $expected, $updates): BillingRecord {
            $bill = BillingRecord::query()->lockForUpdate()->findOrFail($bill->bill_id);
            if ($bill->status !== $expected) {
                throw ValidationException::withMessages(['status' => "สถานะปัจจุบันคือ {$bill->status} ไม่ใช่ {$expected}"]);
            }
            $bill->fill($updates)->save();

            return $bill->fresh('items');
        });
    }

    private function isOwnedBy(BillingRecord|Quotation $record, User $user): bool
    {
        return (int) ($record->creator_user_id ?? 0) === (int) $user->id
            || ($record->creator_user_id === null && $record->created_by === $user->username);
    }
}
