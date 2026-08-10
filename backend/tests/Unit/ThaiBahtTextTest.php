<?php

namespace Tests\Unit;

use App\Services\Documents\ThaiBahtText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ThaiBahtTextTest extends TestCase
{
    /** @return array<string, array{float, string}> */
    public static function amounts(): array
    {
        return [
            'zero' => [0, 'ศูนย์บาทถ้วน'],
            'eleven' => [11, 'สิบเอ็ดบาทถ้วน'],
            'twenty one' => [21, 'ยี่สิบเอ็ดบาทถ้วน'],
            'satang' => [1250.75, 'หนึ่งพันสองร้อยห้าสิบบาทเจ็ดสิบห้าสตางค์'],
            'million' => [1_000_001, 'หนึ่งล้านหนึ่งบาทถ้วน'],
        ];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function it_converts_money_to_thai_text(float $amount, string $expected): void
    {
        $this->assertSame($expected, (new ThaiBahtText)->convert($amount));
    }
}
