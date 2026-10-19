<?php

namespace Tests\Unit;

use App\Services\Documents\QuotationTemplateCatalog;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QuotationTemplateCatalogTest extends TestCase
{
    #[Test]
    public function it_exposes_the_standard_and_six_hospital_layouts_as_downloadable(): void
    {
        $catalog = new QuotationTemplateCatalog;
        $this->assertSame('standard', $catalog->requireAvailable(null));
        $this->assertSame('standard', $catalog->requireAvailable('standard'));
        $this->assertCount(7, $catalog->options());
        $this->assertSame([
            'ใบเสนอราคามาตรฐาน',
            'รพ.ศิริราช',
            'รพ.หลวงพ่อทวีศักดิ์',
            'รพ.ตำรวจ',
            'เจริญกรุงประชารักษ์',
            'รพ.รามา',
            'รพ.ศิริราชปิย',
        ], array_column($catalog->options(), 'name'));
        $this->assertNotContains(false, array_column($catalog->options(), 'available'));
        $this->assertSame('รพ.รามา', $catalog->profile(QuotationTemplateCatalog::RAMATHIBODI)['name']);
        $this->assertSame(QuotationTemplateCatalog::SIRIRAJ, $catalog->requireAvailable(QuotationTemplateCatalog::SIRIRAJ));
        $this->expectException(ValidationException::class);
        $catalog->requireAvailable('unknown-hospital');
    }
}
