<?php

namespace Tests\Unit;

use App\Services\Documents\DocumentService;
use App\Services\Documents\PowerOfAttorneyTemplateCatalog;
use App\Services\Documents\ThaiBahtText;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PowerOfAttorneyDocumentTest extends TestCase
{
    #[Test]
    public function it_renders_the_same_unsigned_draft_even_when_signature_evidence_is_supplied(): void
    {
        $signature = base64_encode((string) file_get_contents(resource_path('assets/images/valorhealth.png')));
        $payload = [
            'authorization_no' => 'POA-QT-2026-0001-R1',
            'quotation_no' => 'QT-2026-0001/R1',
            'issue_date_th' => '15/10/2569',
            'valid_until_th' => '13/01/2570',
            'hospital_name' => 'โรงพยาบาลตัวอย่าง',
            'sale_name' => 'สมชาย ผู้แทน',
            'sale_email' => 'sale@example.com',
            'sale_phone' => '081-234-5678',
            'items' => [['sku' => 'SKU-01', 'description' => 'สินค้าแพทย์ตัวอย่าง', 'quantity' => 2, 'unit' => 'ชิ้น']],
        ];
        $documents = new DocumentService(new ThaiBahtText);
        $draft = $documents->powerOfAttorneyPdf($payload, $signature);
        $final = $documents->powerOfAttorneyPdf($payload, $signature, [
            'name' => 'ผู้จัดการอนุมัติ',
            'signed_at' => '15-10-2026 10:00:00',
            'hash' => str_repeat('a', 64),
            'signature_image' => $signature,
        ]);
        $draftHtml = $documents->powerOfAttorneyPdf($payload, $signature, htmlOnly: true);
        $finalHtml = $documents->powerOfAttorneyPdf($payload, $signature, [
            'name' => 'ผู้จัดการอนุมัติ',
            'signed_at' => '15-10-2026 10:00:00',
            'hash' => str_repeat('a', 64),
            'signature_image' => $signature,
        ], htmlOnly: true);

        $this->assertStringStartsWith('%PDF-', $draft);
        $this->assertStringStartsWith('%PDF-', $final);
        $this->assertGreaterThan(5000, strlen($draft));
        $this->assertSame($draftHtml, $finalHtml);
        $this->assertStringNotContainsString('พื้นที่ลงลายมือชื่อ', $draftHtml);

        $editor = $documents->powerOfAttorneyEditorHtml([
            ...$payload,
            'grantor_one_name' => 'นายประเสริฐ ไวยาวัจมัย',
            'grantor_two_name' => 'นายกฤต เติมพิริยะ',
            'body_intro' => 'ข้อความนำที่แก้ไขได้',
            'body_scope' => 'ขอบเขตอำนาจที่แก้ไขได้',
            'body_closing' => 'ข้อความลงท้ายที่แก้ไขได้',
            'footer_text' => 'ส่วนท้ายที่แก้ไขได้',
        ], $signature);
        foreach (['grantor_one_name', 'grantor_two_name', 'body_intro', 'body_scope', 'body_closing', 'footer_text', 'witness_one_name', 'witness_two_name'] as $field) {
            $this->assertStringContainsString('data-edit-path="power_of_attorney.'.$field.'"', $editor);
        }

        if ($path = getenv('POA_QA_OUTPUT')) {
            file_put_contents($path, $final);
        }
        if ($path = getenv('POA_SAMPLE_OUTPUT')) {
            $sample = $payload;
            $sample['authorization_no'] = 'ตัวอย่างรูปแบบมาตรฐาน';
            $sample['quotation_no'] = 'QT-XXXX/R1';
            $sample['hospital_name'] = 'หน่วยงาน / โรงพยาบาล';
            $sample['sale_name'] = 'ชื่อผู้แทนขาย';
            $sample['sale_email'] = 'sale@example.com';
            $sample['sale_phone'] = '0XX-XXX-XXXX';
            $sample['items'] = [['sku' => 'SKU-XXXX', 'description' => 'รายการสินค้าตามใบเสนอราคา', 'quantity' => 1, 'unit' => 'รายการ']];
            file_put_contents($path, $documents->powerOfAttorneyPdf($sample, ''));
        }
    }

    #[Test]
    public function it_keeps_a_long_item_list_renderable(): void
    {
        $items = [];
        for ($index = 1; $index <= 22; $index++) {
            $items[] = ['sku' => 'SKU-'.$index, 'description' => 'สินค้าแพทย์รายการที่ '.$index.' พร้อมรายละเอียดตามใบเสนอราคา', 'quantity' => 10, 'unit' => 'ชิ้น'];
        }
        $pdf = (new DocumentService(new ThaiBahtText))->powerOfAttorneyPdf([
            'authorization_no' => 'POA-LONG-R1',
            'quotation_no' => 'QT-LONG/R1',
            'issue_date_th' => '15/10/2569',
            'valid_until_th' => '13/01/2570',
            'hospital_name' => 'โรงพยาบาลตัวอย่าง',
            'sale_name' => 'ผู้แทนตัวอย่าง',
            'sale_email' => 'sale@example.com',
            'sale_phone' => '081-234-5678',
            'items' => $items,
        ], '');

        $this->assertStringStartsWith('%PDF-', $pdf);
        if ($path = getenv('POA_LONG_QA_OUTPUT')) {
            file_put_contents($path, $pdf);
        }
    }

    #[Test]
    public function it_renders_each_supported_power_of_attorney_template(): void
    {
        $documents = new DocumentService(new ThaiBahtText);
        $hashes = [];
        foreach ([
            PowerOfAttorneyTemplateCatalog::GENERAL,
            PowerOfAttorneyTemplateCatalog::ANANDAMAHIDOL,
            PowerOfAttorneyTemplateCatalog::EGP,
            PowerOfAttorneyTemplateCatalog::GRANTEE_FORM,
            PowerOfAttorneyTemplateCatalog::ANANDAMAHIDOL_EBIDDING,
        ] as $template) {
            $pdf = $documents->powerOfAttorneyPdf([
                'template_id' => $template,
                'authorization_no' => 'POA-QT-2026-0001-R1',
                'quotation_no' => 'QT-2026-0001/R1',
                'issue_date_long_th' => '16 ตุลาคม 2569',
                'hospital_name' => 'โรงพยาบาลอานันทมหิดล',
                'project' => 'โครงการทดสอบ',
                'sale_name' => 'ผู้แทนทดสอบ',
                'sale_identity_no' => '1234567890123',
                'items' => [],
            ], '');

            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertGreaterThan(5000, strlen($pdf));
            $hashes[] = hash('sha256', $pdf);
            if ($directory = getenv('POA_TEMPLATE_QA_DIR')) {
                file_put_contents(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$template.'.pdf', $pdf);
            }
        }

        $this->assertCount(5, array_unique($hashes));
    }
}
