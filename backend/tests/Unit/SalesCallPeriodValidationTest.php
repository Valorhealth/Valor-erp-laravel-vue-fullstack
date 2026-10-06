<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\SalesCallController;
use App\Models\SalesCallActivity;
use App\Models\User;
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

    #[Test]
    public function sale_saves_preserve_manager_comments_and_ignore_new_submitted_comments(): void
    {
        $request = Request::create('/api/v1/sales-calls/day', 'PUT');
        $user = (new User)->forceFill(['id' => 9, 'username' => 'sale9']);
        $request->setUserResolver(fn (): User => $user);
        $row = [
            'place_of_work' => 'Hospital', 'customer_name' => 'Customer', 'specialty' => 'Nurse',
            'department' => 'OPD', 'customer_group' => 'Nurse', 'product_1' => 'FIBROSCAN',
            'objective' => 'Sales', 'outcome' => 'Success', 'status' => 'ทำงาน',
            'sm_comment' => 'Sale must not control this value',
        ];
        $method = new ReflectionMethod(SalesCallController::class, 'activityValues');

        $existing = (new SalesCallActivity)->forceFill([
            'sm_comment' => 'SM feedback', 'estimated_value' => 0, 'actual_value' => 0,
        ]);
        $existing->exists = true;
        $new = new SalesCallActivity;

        $existingValues = $method->invoke(new SalesCallController, $request, $row, '2026-10-01', 1, $existing);
        $newValues = $method->invoke(new SalesCallController, $request, $row, '2026-10-01', 2, $new);

        $this->assertSame('SM feedback', $existingValues['sm_comment']);
        $this->assertNull($newValues['sm_comment']);
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
