<?php

namespace App\Services\SalesCalls;

use App\Models\SalesCallMonthlySubmission;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SalesCallSubmissionTracker
{
    public function markExcel(int $sellerUserId, string|CarbonInterface $month, ?CarbonInterface $occurredAt = null): void
    {
        $this->mark($sellerUserId, $month, 'used_excel', $occurredAt);
    }

    /** @param iterable<int, string|CarbonInterface> $months
     * @return array<int, string> Newly submitted months.
     */
    public function markWebsite(int $sellerUserId, iterable $months, ?CarbonInterface $occurredAt = null): array
    {
        $newlySubmitted = [];
        foreach (collect($months)->map(fn (string|CarbonInterface $month): string => $this->month($month))->unique() as $month) {
            if ($this->mark($sellerUserId, $month, 'used_website', $occurredAt)) {
                $newlySubmitted[] = $month;
            }
        }

        return $newlySubmitted;
    }

    public function forget(int $sellerUserId, string|CarbonInterface $month): void
    {
        SalesCallMonthlySubmission::query()
            ->where('seller_user_id', $sellerUserId)
            ->where('report_month', $this->month($month))
            ->delete();
    }

    private function mark(int $sellerUserId, string|CarbonInterface $month, string $channel, ?CarbonInterface $occurredAt): bool
    {
        return DB::transaction(function () use ($sellerUserId, $month, $channel, $occurredAt): bool {
            $reportMonth = $this->month($month);
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["sales-call-submission-{$sellerUserId}-{$reportMonth}"]);
            $timestamp = $occurredAt ?? now();
            $submission = SalesCallMonthlySubmission::query()->firstOrNew([
                'seller_user_id' => $sellerUserId,
                'report_month' => $reportMonth,
            ]);
            $isFirstSubmission = ! $submission->exists;
            if ($isFirstSubmission) {
                $submission->first_submitted_at = $timestamp;
            }
            $submission->{$channel} = true;
            $submission->last_edited_at = $timestamp;
            $submission->save();

            return $isFirstSubmission;
        });
    }

    private function month(string|CarbonInterface $value): string
    {
        return ($value instanceof CarbonInterface ? Carbon::instance($value) : Carbon::parse($value))
            ->startOfMonth()
            ->toDateString();
    }
}
