<?php

namespace Tests\Unit;

use App\Services\Quotations\QuotationNumberGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class QuotationNumberGeneratorTest extends TestCase
{
    #[Test]
    public function it_uses_the_highest_number_for_the_selected_month(): void
    {
        $period = QuotationNumberGenerator::periodForDate('2026-08-14');
        $next = QuotationNumberGenerator::nextSequence(latestIssued: 7, configuredFloor: 0);

        $this->assertSame('2608', $period);
        $this->assertSame('QT-2608-0008', QuotationNumberGenerator::format($period, $next));
    }

    #[Test]
    public function it_starts_a_new_month_at_one(): void
    {
        $period = QuotationNumberGenerator::periodForDate('2026-09-01');
        $next = QuotationNumberGenerator::nextSequence(latestIssued: 0, configuredFloor: 0);

        $this->assertSame('QT-2609-0001', QuotationNumberGenerator::format($period, $next));
    }

    #[Test]
    public function it_can_continue_from_a_configured_external_floor(): void
    {
        $next = QuotationNumberGenerator::nextSequence(latestIssued: 30, configuredFloor: 150);

        $this->assertSame('QT-2608-0151', QuotationNumberGenerator::format('2608', $next));
    }
}
