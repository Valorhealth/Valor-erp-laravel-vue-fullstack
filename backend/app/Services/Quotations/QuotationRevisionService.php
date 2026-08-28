<?php

namespace App\Services\Quotations;

use App\Models\BillingRecord;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;

final class QuotationRevisionService
{
    public function record(BillingRecord $bill, string $actor, ?string $note = null, bool $increment = false): void
    {
        if (! $bill->ref_qt_id) {
            return;
        }

        $quotation = Quotation::query()->lockForUpdate()->find($bill->ref_qt_id);
        if (! $quotation) {
            return;
        }
        $revision = $increment ? max((int) $bill->revision_no, (int) $quotation->revision_no) + 1 : max(1, (int) $bill->revision_no);
        $bill->forceFill(['revision_no' => $revision])->save();
        $quotation->forceFill([
            'revision_no' => $revision,
            ...collect($bill->getAttributes())->only($this->commercialFields())->all(),
        ])->save();

        $snapshot = [
            'quotation' => $quotation->fresh()->toArray(),
            'billing_record' => $bill->fresh()->toArray(),
            'items' => $bill->items()->orderBy('item_id')->get()->toArray(),
        ];
        DB::table('quotation_revisions')->updateOrInsert(
            ['qt_id' => $quotation->qt_id, 'revision_no' => $revision],
            ['bill_id' => $bill->bill_id, 'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'changed_by' => $actor, 'change_note' => $note, 'created_at' => now()],
        );
    }

    /** @return array<int, string> */
    public function commercialFields(): array
    {
        return ['customer_po_no', 'customer_confirmed_at', 'customer_contact_name', 'customer_contact_channel', 'quotation_valid_until', 'payment_terms', 'delivery_due_date', 'delivery_terms'];
    }
}
