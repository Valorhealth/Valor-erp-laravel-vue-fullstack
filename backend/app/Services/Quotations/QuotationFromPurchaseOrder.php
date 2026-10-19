<?php

namespace App\Services\Quotations;

use App\Models\BillingRecord;
use App\Models\EditLog;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class QuotationFromPurchaseOrder
{
    private const CUSTOMER_FIELDS = [
        'buyer_name', 'tax_id', 'province', 'district', 'sub_district', 'zipcode',
        'address_note', 'shipping_type', 'shipping_text', 'bill_note',
        'bplus_customer_code', 'sale_discount_total', 'sale_discount_note',
        'credit_limit', 'credit_term', 'requested_credit_term', 'customer_po_no',
        'customer_confirmed_at', 'customer_contact_name', 'customer_contact_channel',
        'quotation_valid_until', 'payment_terms', 'delivery_due_date', 'delivery_terms',
        'revision_no', 'total_amount',
    ];

    private const ITEM_FIELDS = [
        'main_category', 'sub_category', 'customer_product_name', 'unit_type',
        'quantity', 'unit_price', 'sale_discount_unit', 'line_total_before_discount', 'line_total',
    ];

    public function createOrGet(User $actor, BillingRecord $source, QuotationNumberGenerator $numbers): Quotation
    {
        return DB::transaction(function () use ($actor, $source, $numbers): Quotation {
            $bill = BillingRecord::query()->lockForUpdate()->with('items')->findOrFail($source->bill_id);
            if ($bill->ref_qt_id) {
                return Quotation::query()->findOrFail($bill->ref_qt_id);
            }
            if ($bill->workflow_type !== 'customer_po_direct') {
                throw ValidationException::withMessages(['bill' => 'รายการนี้ไม่ได้เปิดบิลจาก PO โดยตรง']);
            }
            if (! $bill->documents()->where('document_type', 'customer_po')->exists()) {
                throw ValidationException::withMessages(['bill' => 'ไม่พบไฟล์ PO ปัจจุบันของรายการนี้']);
            }
            if ($bill->items->isEmpty()) {
                throw ValidationException::withMessages(['bill' => 'รายการนี้ยังไม่มีสินค้า จึงสร้างใบเสนอราคาไม่ได้']);
            }

            $issuedDate = now()->toDateString();
            $quotation = Quotation::query()->create([
                ...self::attributesFromBill($bill),
                'qt_date' => $issuedDate,
                'qt_no' => $numbers->nextForDate($issuedDate),
                'workflow_type' => 'customer_po_direct',
                'status' => 'เอกสารประกอบ PO',
                'created_by' => $bill->created_by,
                'creator_user_id' => $bill->creator_user_id,
                'created_at' => now(),
            ]);
            foreach ($bill->items as $item) {
                $quotation->items()->create(self::itemAttributes($item->getAttributes()));
            }
            $bill->forceFill(['ref_qt_id' => $quotation->qt_id])->save();
            EditLog::query()->create([
                'bill_id' => $bill->bill_id,
                'edited_by' => $actor->username,
                'edit_time' => now(),
                'edit_summary' => 'สร้างใบเสนอราคา '.$quotation->qt_no.' จาก PO ภายหลัง โดยไม่สร้างบิลใหม่',
            ]);

            return $quotation;
        });
    }

    /** @return array<string, mixed> */
    public static function attributesFromBill(BillingRecord $bill): array
    {
        return Arr::only($bill->getAttributes(), self::CUSTOMER_FIELDS);
    }

    /** @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function itemAttributes(array $item): array
    {
        return Arr::only($item, self::ITEM_FIELDS);
    }
}
