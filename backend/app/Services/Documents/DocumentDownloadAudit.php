<?php

namespace App\Services\Documents;

use App\Models\BillingRecord;
use App\Models\Quotation;
use Illuminate\Http\Request;

final class DocumentDownloadAudit
{
    public static function capture(Request $request, string $type, ?Quotation $quotation = null, ?BillingRecord $bill = null, array $extra = []): void
    {
        $request->attributes->set('document_audit', [
            'type' => $type,
            'quotation_id' => $quotation?->qt_id ?? $bill?->ref_qt_id,
            'quotation_no' => $quotation?->qt_no,
            'bill_id' => $bill?->bill_id,
            'revision_no' => max(1, (int) ($bill?->revision_no ?? $quotation?->revision_no ?? 1)),
            ...$extra,
        ]);
    }
}
