<?php

namespace App\Services\Billing;

use App\Models\BillingRecord;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BillingCancellationService
{
    public function cancel(User $actor, BillingRecord $bill, string $reason): BillingRecord
    {
        return DB::transaction(function () use ($actor, $bill, $reason): BillingRecord {
            $locked = BillingRecord::query()->lockForUpdate()->findOrFail($bill->bill_id);
            if ($locked->status === 'ยกเลิกบิล') {
                throw ValidationException::withMessages(['status' => 'บิลนี้ถูกยกเลิกไว้แล้ว']);
            }
            $locked->forceFill([
                'status' => 'ยกเลิกบิล',
                'canceled_at' => now(),
                'canceled_by' => $actor->username,
                'cancellation_reason' => trim($reason),
            ])->save();
            if ($locked->ref_qt_id) {
                Quotation::query()->whereKey($locked->ref_qt_id)->update([
                    'status' => 'ยกเลิกใบเสนอราคา',
                    'canceled_at' => now(),
                    'canceled_by' => $actor->username,
                    'cancellation_reason' => trim($reason),
                ]);
            }

            return $locked->fresh(['items', 'documents']);
        });
    }
}
