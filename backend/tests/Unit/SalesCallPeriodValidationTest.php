<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\SalesCallController;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class SalesCallPeriodValidationTest extends TestCase
{
    #[Test]
    public function it_accepts_the_sixteen_day_second_half_of_a_31_day_month(): void
    {
        $range = $this->validateRange('2026-08-16', '2026-08-31');

        $this->assertSame([
            'date_from' => '2026-08-16',
            'date_to' => '2026-08-31',
        ], $range);
    }

    #[Test]
    public function it_rejects_ranges_longer_than_sixteen_days(): void
    {
        $this->expectException(ValidationException::class);

        $this->validateRange('2026-08-01', '2026-08-17');
    }

    /** @return array{date_from: string, date_to: string} */
    private function validateRange(string $from, string $to): array
    {
        $method = new ReflectionMethod(SalesCallController::class, 'validatedPeriod');

        return $method->invoke(
            new SalesCallController,
            Request::create('/api/v1/sales-calls/period', 'GET', ['date_from' => $from, 'date_to' => $to]),
        );
    }
}
