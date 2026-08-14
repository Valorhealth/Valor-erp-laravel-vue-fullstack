<?php

namespace App\Services\Quotations;

use App\Models\Quotation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class QuotationNumberGenerator
{
    public function nextForDate(CarbonInterface|string $date): string
    {
        $period = self::periodForDate($date);

        $connection = DB::connection();
        if ($connection->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["quotation-number-{$period}"]);
        }

        $latestIssued = $this->latestIssuedNumber($period);
        $configuredFloor = (int) (DB::table('quotation_number_counters')
            ->where('period', $period)
            ->lockForUpdate()
            ->value('last_number') ?? 0);
        $next = self::nextSequence($latestIssued, $configuredFloor);

        DB::table('quotation_number_counters')->updateOrInsert(
            ['period' => $period],
            ['last_number' => $next, 'updated_at' => now()],
        );

        return self::format($period, $next);
    }

    public static function periodForDate(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface
            ? $date->format('ym')
            : CarbonImmutable::parse($date)->format('ym');
    }

    public static function nextSequence(int $latestIssued, int $configuredFloor): int
    {
        return max($latestIssued, $configuredFloor) + 1;
    }

    public static function format(string $period, int $sequence): string
    {
        return 'QT-'.$period.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function latestIssuedNumber(string $period): int
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return (int) (Quotation::query()
                ->where('qt_no', 'like', "QT-{$period}-%")
                ->selectRaw("COALESCE(MAX(CAST(SUBSTRING(qt_no FROM '[0-9]+$') AS INTEGER)), 0) AS latest_number")
                ->value('latest_number') ?? 0);
        }

        return Quotation::query()
            ->where('qt_no', 'like', "QT-{$period}-%")
            ->pluck('qt_no')
            ->reduce(function (int $latest, mixed $number) use ($period): int {
                if (! preg_match('/^QT-'.preg_quote($period, '/').'-(\d+)$/', (string) $number, $matches)) {
                    return $latest;
                }

                return max($latest, (int) $matches[1]);
            }, 0);
    }
}
