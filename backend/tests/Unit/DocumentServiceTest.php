<?php

namespace Tests\Unit;

use App\Models\BillingRecord;
use App\Models\BillItem;
use App\Models\Quotation;
use App\Models\QuotationDocumentVersion;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Documents\QuotationTemplateCatalog;
use App\Services\Documents\ThaiBahtText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentServiceTest extends TestCase
{
    #[Test]
    public function it_keeps_a_product_image_below_its_description(): void
    {
        $service = new DocumentService(new ThaiBahtText);
        $method = new \ReflectionMethod($service, 'descriptionWithImage');
        $html = $method->invoke(
            $service,
            '<span>ชื่อสินค้า</span>',
            'data:image/png;base64,AAAA',
            0,
            ['overrides' => ['_editor' => false]],
        );

        $descriptionPosition = strpos($html, 'item-description-content');
        $imagePosition = strpos($html, 'item-description-image-wrap');

        $this->assertIsInt($descriptionPosition);
        $this->assertIsInt($imagePosition);
        $this->assertLessThan($imagePosition, $descriptionPosition);
        $this->assertStringContainsString('<div class="item-description-content"><span>ชื่อสินค้า</span></div>', $html);
    }

    #[Test]
    public function it_preserves_multiline_quantities_and_calculates_matching_amount_lines(): void
    {
        $payload = [
            'template' => QuotationTemplateCatalog::STANDARD,
            'mode' => 'original',
            'document' => ['number' => 'QT-ML', 'date' => '2026-10-22', 'recipient' => 'ลูกค้าทดสอบ', 'salesperson' => 'ฝ่ายขาย'],
            'customer' => ['show_billing_address' => false, 'show_delivery_address' => false],
            'items' => [[
                'sku' => 'FIBRO', 'description' => "กรุงเทพ\nสุราษฎร์ธานี", 'quantity' => 2, 'quantity_display' => "1\n1",
                'unit' => "ครั้ง/วัน\nครั้ง/วัน", 'pack' => '', 'unit_price' => 50000, 'unit_price_display' => "50,000\n85,000",
                'discount' => 0, 'discount_display' => "0\n0", 'remark' => '', 'origin_details' => '', 'image' => '',
            ]],
            'table_layout' => [
                ['id' => 'description', 'field' => 'description', 'label' => 'รายการ', 'width' => 40, 'align' => 'left'],
                ['id' => 'quantity', 'field' => 'quantity', 'label' => 'จำนวน', 'width' => 20, 'align' => 'right'],
                ['id' => 'unit-price', 'field' => 'unit_price', 'label' => 'ราคา', 'width' => 20, 'align' => 'right'],
                ['id' => 'amount', 'field' => 'amount', 'label' => 'รวม', 'width' => 20, 'align' => 'right'],
            ],
            'layout' => ['show_summary' => false, 'show_customer_details' => false],
            'terms' => [], 'bill_discount' => 0,
        ];

        $html = (new DocumentService(new ThaiBahtText))->quotationEditorPdf($payload, true);

        $this->assertStringContainsString("1<br>\n1", $html);
        $this->assertStringContainsString("50,000<br>\n85,000", $html);
        $this->assertStringContainsString("50,000.00<br>\n85,000.00", $html);
    }

    #[Test]
    public function it_keeps_alignment_lines_blank_instead_of_rendering_extra_zero_amounts(): void
    {
        $payload = [
            'template' => QuotationTemplateCatalog::STANDARD,
            'mode' => 'original',
            'document' => ['number' => 'QT-BLANK', 'date' => '2026-10-22', 'recipient' => 'ลูกค้าทดสอบ', 'salesperson' => 'ฝ่ายขาย'],
            'customer' => ['show_billing_address' => false, 'show_delivery_address' => false],
            'items' => [[
                'sku' => 'FIBRO', 'description' => "หัวข้อ\n\nกรุงเทพ", 'quantity' => 1, 'quantity_display' => "\n\n1\n",
                'unit' => 'ครั้ง/วัน', 'pack' => '', 'unit_price' => 50000, 'unit_price_display' => '50,000',
                'discount' => 0, 'remark' => '', 'origin_details' => '', 'image' => '',
            ]],
            'table_layout' => [
                ['id' => 'description', 'field' => 'description', 'label' => 'รายการ', 'width' => 40, 'align' => 'left'],
                ['id' => 'quantity', 'field' => 'quantity', 'label' => 'จำนวน', 'width' => 20, 'align' => 'right'],
                ['id' => 'unit-price', 'field' => 'unit_price', 'label' => 'ราคา', 'width' => 20, 'align' => 'right'],
                ['id' => 'amount', 'field' => 'amount', 'label' => 'รวม', 'width' => 20, 'align' => 'right'],
            ],
            'layout' => ['show_summary' => false, 'show_customer_details' => false],
            'terms' => [], 'bill_discount' => 0,
        ];

        $html = (new DocumentService(new ThaiBahtText))->quotationEditorPdf($payload, true);
        $this->assertStringContainsString("<br>\n<br>\n50,000.00<br>\n", $html);
        $this->assertStringNotContainsString(">0.00<br>\n", $html);
    }

    #[Test]
    public function it_generates_a_quotation_pdf_without_querying_the_database(): void
    {
        $quotation = new Quotation([
            'qt_no' => 'QT-TEST-0001', 'qt_date' => '2026-07-22', 'buyer_name' => 'โรงพยาบาลทดสอบ',
            'bplus_customer_code' => 'AR001', 'address_note' => 'กรุงเทพมหานคร', 'tax_id' => '0105558041179',
            'created_by' => 'sale-test', 'bill_note' => 'ทดสอบ', 'total_amount' => 1070, 'sale_discount_total' => 0,
        ]);
        $quotation->qt_id = 1;
        $quotation->setRelation('items', new Collection([
            new QuotationItem(['qt_item_id' => 10, 'main_category' => 'SKU001', 'sub_category' => 'สินค้าทดสอบ', 'unit_type' => 'ชิ้น', 'quantity' => 1, 'unit_price' => 1000, 'line_total_before_discount' => 1000, 'line_total' => 1000]),
        ]));

        $documents = new DocumentService(new ThaiBahtText);
        $pdf = $documents->quotationPdf($quotation, true);
        $fontCache = json_decode((string) file_get_contents(storage_path('app/private/dompdf/fonts/installed-fonts.json')), true);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
        $this->assertArrayHasKey('thsarabun', $fontCache);
        $this->assertFileExists($fontCache['thsarabun']['normal'].'.ttf');
        $this->assertSame('COPY_Quotation_โรงพยาบาลทดสอบ_2026-07-22.pdf', $documents->quotationDownloadName($quotation, true));
        $this->assertStringContainsString("filename*=utf-8''COPY_Quotation_", $documents->attachmentDisposition($documents->quotationDownloadName($quotation, true)));

        $customPdf = $documents->quotationPdf($quotation, itemNameOverrides: [10 => 'ชื่อสินค้าตามโรงพยาบาล']);
        $this->assertStringStartsWith('%PDF-', $customPdf);
        $this->assertSame('สินค้าทดสอบ', $quotation->items->first()->sub_category);
    }

    #[Test]
    public function it_renders_each_hospital_quotation_with_its_own_columns_and_terms(): void
    {
        $quotation = new Quotation([
            'qt_no' => 'AV-TEST-2026', 'qt_date' => '2026-10-14', 'buyer_name' => 'โรงพยาบาลทดสอบ',
            'created_by' => 'P\'Test Sale', 'bill_note' => 'เงื่อนไขจากรายการขาย', 'total_amount' => 1070,
            'sale_discount_total' => 0,
        ]);
        $quotation->qt_id = 100;
        $quotation->setRelation('creator', new User([
            'department' => 'VH002', 'first_name' => 'Anurak', 'last_name' => 'Sale', 'phone' => '084-679-6542',
        ]));
        $quotation->setRelation('items', collect([
            new QuotationItem([
                'qt_item_id' => 1, 'main_category' => 'SKU-01', 'sub_category' => 'สินค้าทดสอบ',
                'unit_type' => 'ชิ้น', 'quantity' => 2, 'unit_price' => 535,
                'line_total_before_discount' => 1070, 'line_total' => 1070,
            ]),
        ]));
        $documents = new DocumentService(new ThaiBahtText);
        $expectations = [
            QuotationTemplateCatalog::SIRIRAJ => ['คณะแพทยศาสตร์ศิริราชพยาบาล', 'รหัสเวชภัณฑ์', 'จำนวนและขนาดบรรจุ'],
            QuotationTemplateCatalog::LUANGPHO_THAWEESAK => ['โรงพยาบาลหลวงพ่อทวีศักดิ์', 'Pcode', 'Gross Amount', 'Value Added Tax 7%'],
            QuotationTemplateCatalog::POLICE_GENERAL => ['โรงพยาบาลตำรวจ', 'ขอเสนอราคาวัสดุอุปกรณ์ทางการแพทย์', 'ผลิตภัณฑ์ AVANOS ประเทศสหรัฐอเมริกา'],
            QuotationTemplateCatalog::CHAROENKRUNG_PRACHARAK => ['โรงพยาบาลเจริญกรุงประชารักษ์', 'กำหนดยืนราคา 360 วัน', '101-242025-0'],
            QuotationTemplateCatalog::RAMATHIBODI => ['โรงพยาบาลรามาธิบดี', 'ลักษณะนาม', 'ผู้ใช้งานรับทราบการเปลี่ยนแปลงชื่อเวชภัณฑ์', '(ผู้แทนขาย Anurak Sale)', 'โทร. 084-679-6542'],
            QuotationTemplateCatalog::SIRIRAJ_PIYAMAHARAJKARUN => ['โรงพยาบาลศิริราช ปิยมหาราชการุณย์', 'รหัส SAP', 'ยอดขั้นต่ำในการเปิดบิล 1,000 บาท'],
        ];

        foreach ($expectations as $template => $needles) {
            $html = $documents->quotationPdf($quotation, htmlOnly: true, template: $template);
            $this->assertStringContainsString('SKU-01', $html);
            $this->assertStringContainsString('สินค้าทดสอบ', $html);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $html, $template);
            }
            $pdf = $documents->quotationPdf($quotation, template: $template);
            $this->assertStringStartsWith('%PDF-', $pdf, $template);
            $this->assertGreaterThan(5000, strlen($pdf), $template);
        }
    }

    #[Test]
    public function it_renders_an_editor_snapshot_with_inline_overrides_and_without_an_automatic_signature_image(): void
    {
        $payload = [
            'template' => QuotationTemplateCatalog::POLICE_GENERAL,
            'mode' => 'revised',
            'document' => [
                'number' => 'AV-CUSTOM-001/R2',
                'date' => '2026-10-14',
                'subject' => "หัวข้อที่แก้ไขจากหน้าเอกสาร\n        บริษัท เวเลอร์ เฮลธ์ จำกัด",
                'recipient' => 'ผู้อำนวยการโรงพยาบาลตัวอย่าง',
                'intro' => 'ข้อความเกริ่นนำฉบับแก้ไข',
                'salesperson' => 'P\'Editor Sale',
                'signature_role' => 'ผู้เสนอราคา',
                'contact' => 'ติดต่อ 02-000-0000',
            ],
            'layout' => ['title_alignment' => 'right', 'logo_alignment' => 'left'],
            'items' => [[
                'row_key' => '1', 'sku' => 'SKU-EDIT', 'description' => 'ชื่อสินค้าฉบับโรงพยาบาล',
                'quantity' => 2, 'unit' => 'กล่อง', 'pack' => '10 ชิ้น/กล่อง', 'unit_price' => 535,
                'discount' => 0, 'remark' => 'หมายเหตุรายการ',
            ]],
            'bill_discount' => 0,
            'terms' => ['เงื่อนไขที่ Sale แก้ไขเอง'],
        ];
        $documents = new DocumentService(new ThaiBahtText);
        $html = $documents->quotationEditorPdf($payload, true);
        $editorHtml = $documents->quotationEditorHtml($payload);
        $pdf = $documents->quotationEditorPdf($payload);

        $this->assertStringContainsString('หัวข้อที่แก้ไขจากหน้าเอกสาร', $html);
        $this->assertStringContainsString('ผู้อำนวยการโรงพยาบาลตัวอย่าง', $html);
        $this->assertStringContainsString('เงื่อนไขที่ Sale แก้ไขเอง', $html);
        $this->assertStringContainsString('SKU-EDIT', $html);
        $this->assertStringNotContainsString('data:image/png;base64', $html);
        $this->assertStringContainsString('data-edit-path="document.subject"', $editorHtml);
        $this->assertStringContainsString('data-edit-path="items.0.description"', $editorHtml);
        $this->assertStringContainsString('data-edit-path="terms.0"', $editorHtml);
        $this->assertStringContainsString('contenteditable="true"', $editorHtml);
        $this->assertStringNotContainsString('<tr class="empty">', $html);
        $this->assertStringNotContainsString('<tr class="empty">', $editorHtml);
        $this->assertStringContainsString('.preserve-spacing,[data-edit-path]{white-space:pre-wrap}', $html);
        $this->assertStringContainsString('document-title align-right', $html);
        $this->assertStringContainsString('<body class="editor-mode">', $editorHtml);
        $this->assertStringContainsString('.editor-mode .footer{position:static', $editorHtml);
        $this->assertStringContainsString('bottom:-62px', $html);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
    }

    #[Test]
    public function it_preserves_indentation_and_supports_right_aligned_document_titles(): void
    {
        $payload = [
            'template' => QuotationTemplateCatalog::POLICE_GENERAL,
            'mode' => 'original',
            'layout' => ['title_alignment' => 'right', 'logo_alignment' => 'left'],
            'document' => [
                'number' => 'AV-LAYOUT-001',
                'date' => '2026-10-22',
                'subject' => "เรียน ผู้อำนวยการ\n      บริษัท เวเลอร์ เฮลธ์ จำกัด",
                'recipient' => 'ผู้อำนวยการโรงพยาบาลตัวอย่าง',
                'salesperson' => 'ฝ่ายขาย',
            ],
            'items' => [[
                'row_key' => '1', 'sku' => 'SKU-1', 'description' => 'สินค้าทดสอบ',
                'quantity' => 1, 'unit' => 'ชิ้น', 'pack' => '', 'unit_price' => 100,
                'discount' => 0, 'remark' => '', 'origin_details' => '', 'image' => '',
            ]],
            'bill_discount' => 0,
            'terms' => [],
        ];

        $html = (new DocumentService(new ThaiBahtText))->quotationEditorPdf($payload, true);

        $this->assertStringContainsString('.preserve-spacing,[data-edit-path]{white-space:pre-wrap}', $html);
        $this->assertStringContainsString('document-title align-right', $html);
        $this->assertStringContainsString('เรียน ผู้อำนวยการ<br>', $html);
        $this->assertStringContainsString(str_repeat('&nbsp;', 6).'บริษัท เวเลอร์ เฮลธ์ จำกัด', $html);
        $this->assertStringContainsString('logo align-left', $html);

        $previewHtml = (new DocumentService(new ThaiBahtText))->quotationEditorPdf($payload, true);
        $this->assertStringContainsString('class="preserve-spacing"', $previewHtml);
    }

    #[Test]
    public function it_can_hide_the_price_summary_without_hiding_hospital_terms(): void
    {
        $payload = [
            'template' => QuotationTemplateCatalog::POLICE_GENERAL,
            'mode' => 'original',
            'layout' => ['show_summary' => false],
            'document' => ['number' => 'AV-HIDE-SUMMARY', 'date' => '2026-10-22', 'salesperson' => 'ฝ่ายขาย'],
            'items' => [[
                'row_key' => '1', 'sku' => 'SKU-1', 'description' => 'บริการ FibroScan',
                'quantity' => 1, 'unit' => 'ครั้ง', 'pack' => '', 'unit_price' => 50000,
                'discount' => 0, 'remark' => '', 'origin_details' => '', 'image' => '',
            ]],
            'terms' => ['กำหนดยืนยันราคา 60 วัน'],
            'bill_discount' => 0,
        ];

        $documents = new DocumentService(new ThaiBahtText);
        $html = $documents->quotationEditorPdf($payload, true);

        $this->assertStringContainsString('กำหนดยืนยันราคา 60 วัน', $html);
        $this->assertStringNotContainsString('data-edit-path="document.summary_total_label"', $html);
        $this->assertStringNotContainsString('จำนวนเงินรวม', $html);

        $standardPayload = [
            ...$payload,
            'template' => QuotationTemplateCatalog::STANDARD,
            'document' => [
                ...$payload['document'],
                'recipient' => 'ลูกค้าทดสอบ',
                'company_name' => 'บริษัททดสอบการซิงก์ Preview',
                'contact' => 'SYNC-PREVIEW-MARKER',
                'confirmation_terms' => 'เงื่อนไขยังคงอยู่',
                'document_note' => 'หมายเหตุยังคงอยู่',
            ],
            'customer' => [
                'code' => 'AR-TEST', 'billing_address' => 'กรุงเทพฯ', 'delivery_address' => '',
                'show_billing_address' => true, 'show_delivery_address' => false, 'tax_id' => '-',
            ],
        ];
        $standardHtml = $documents->quotationEditorPdf($standardPayload, true);
        $standardEditorHtml = $documents->quotationEditorHtml($standardPayload);

        $this->assertStringNotContainsString('<table class="summary">', $standardHtml);
        $this->assertStringNotContainsString('<tr class="empty">', $standardHtml);
        $this->assertStringNotContainsString('<tr class="empty">', $standardEditorHtml);
        $this->assertStringContainsString('เงื่อนไขยังคงอยู่', $standardHtml);
        $this->assertStringContainsString('บริษัททดสอบการซิงก์ Preview', $standardHtml);
        $this->assertStringContainsString('SYNC-PREVIEW-MARKER', $standardHtml);
        $this->assertStringContainsString('บริษัททดสอบการซิงก์ Preview', $standardEditorHtml);
        $this->assertStringContainsString('SYNC-PREVIEW-MARKER', $standardEditorHtml);
        $this->assertStringContainsString('<img class="logo" src="data:image/png;base64,', $standardEditorHtml);
        $this->assertStringNotContainsString('.editor-mode .editable-column{position:relative;padding:', $standardEditorHtml);
        $this->assertStringContainsString('หมายเหตุยังคงอยู่', $standardHtml);
        $this->assertStringContainsString('class="footer standard-footer"', $standardHtml);
        $this->assertStringContainsString('Valor Health Co., Ltd.', $standardHtml);
        $this->assertStringContainsString('Tax ID.0105558041179', $standardHtml);
        $this->assertLessThan(
            strpos($standardHtml, 'ผู้มีอำนาจอนุมัติของบริษัท'),
            strpos($standardHtml, 'ผู้มีอำนาจของลูกค้า / ผู้ยืนยันคำสั่งซื้อ'),
        );
        $this->assertStringNotContainsString('หลักฐานอิเล็กทรอนิกส์ผูกกับ Revision', $standardHtml);
        $this->assertStringNotContainsString('ใบเสนอราคานี้ไม่ใช่ใบกำกับภาษี', $standardHtml);

        $standardPayload['layout']['show_customer_details'] = false;
        $standardPayload['layout']['show_confirmation_terms'] = false;
        $standardPayload['layout']['show_document_note'] = false;
        $standardPayload['layout']['show_signatures'] = false;
        $withoutCustomerDetails = $documents->quotationEditorPdf($standardPayload, true);
        $this->assertStringNotContainsString('<table class="customer">', $withoutCustomerDetails);
        $this->assertStringNotContainsString('เงื่อนไขการยืนยัน:', $withoutCustomerDetails);
        $this->assertStringNotContainsString('<strong>หมายเหตุ:</strong>', $withoutCustomerDetails);
        $this->assertStringNotContainsString('class="signature-table"', $withoutCustomerDetails);
        $this->assertStringContainsString('บริการ FibroScan', $withoutCustomerDetails);
        $this->assertStringContainsString('class="footer standard-footer"', $withoutCustomerDetails);

        $standardPayload['layout']['show_signatures'] = true;
        $standardPayload['layout']['show_company_signature'] = true;
        $standardPayload['layout']['show_customer_signature'] = false;
        $companySignatureOnly = $documents->quotationEditorPdf($standardPayload, true);
        $this->assertStringContainsString('ผู้มีอำนาจอนุมัติของบริษัท', $companySignatureOnly);
        $this->assertStringNotContainsString('ผู้มีอำนาจของลูกค้า / ผู้ยืนยันคำสั่งซื้อ', $companySignatureOnly);
        $this->assertStringContainsString('signature-table company-signature-only', $companySignatureOnly);

        $standardPayload['layout']['show_company_signature'] = false;
        $standardPayload['layout']['show_customer_signature'] = true;
        $customerSignatureOnly = $documents->quotationEditorPdf($standardPayload, true);
        $this->assertStringContainsString('signature-table customer-signature-only', $customerSignatureOnly);
        $this->assertStringContainsString('ผู้มีอำนาจของลูกค้า / ผู้ยืนยันคำสั่งซื้อ', $customerSignatureOnly);
        $this->assertStringNotContainsString('ผู้มีอำนาจอนุมัติของบริษัท', $customerSignatureOnly);
    }

    #[Test]
    public function it_restores_mandatory_hospital_wording_when_an_older_payload_is_incomplete(): void
    {
        $basePayload = [
            'mode' => 'original',
            'document' => [
                'number' => 'AV-TEST', 'date' => '2026-10-15', 'salesperson' => 'ณัฐริณีย์ เกตุคลองเขิน',
                'contact' => 'ชื่อผู้แทน และ Client ณัฐริณีย์ เกตุคลองเขิน เบอร์ติดต่อ 092-820 7224',
            ],
            'items' => [[
                'row_key' => '1', 'sku' => '0112-20', 'description' => 'GASTRO TUBE', 'quantity' => 1,
                'unit' => 'EA', 'pack' => '1 EA', 'unit_price' => 100, 'discount' => 0, 'origin_details' => '',
            ]],
            'terms' => [],
        ];
        $documents = new DocumentService(new ThaiBahtText);

        foreach ([QuotationTemplateCatalog::LUANGPHO_THAWEESAK, QuotationTemplateCatalog::CHAROENKRUNG_PRACHARAK] as $template) {
            $html = $documents->quotationEditorPdf([...$basePayload, 'template' => $template], true);
            $this->assertStringContainsString('ชื่อผู้ผลิต AVENT S.DE.R.L. DE C.V.', $html);
            $this->assertStringContainsString('ประเทศที่ผลิต United Mexican states.', $html);
            $this->assertStringContainsString('ไม่มีผลิตในประเทศไทย', $html);
            $this->assertStringContainsString('ไม่มี มอก. บริษัทเป็นผู้แทนจำหน่ายแต่เพียงผู้เดียว', $html);
        }

        $rama = $documents->quotationEditorPdf([...$basePayload, 'template' => QuotationTemplateCatalog::RAMATHIBODI], true);
        $this->assertStringContainsString('ผลิตภัณฑ์จากประเทศ USA', $rama);

        $sirirajPiya = $documents->quotationEditorPdf([...$basePayload, 'template' => QuotationTemplateCatalog::SIRIRAJ_PIYAMAHARAJKARUN], true);
        $this->assertStringContainsString('โรงพยาบาลศิริราช ปิยมหาราชการุณย์ตรวจพบว่ามีราคาต่างกัน', $sirirajPiya);
        $this->assertStringContainsString('เงื่อนไขในการแลกเปลี่ยนสินค้าก่อนหมดอายุ.....1....ปี', $sirirajPiya);
        $this->assertStringContainsString('ยอดขั้นต่ำในการเปิดบิล 1,000 บาท', $sirirajPiya);
        $this->assertStringContainsString('ชื่อผู้แทน และ Client ณัฐริณีย์ เกตุคลองเขิน เบอร์ติดต่อ 092-820 7224', $sirirajPiya);
    }

    #[Test]
    public function it_places_product_origin_in_the_description_and_only_signs_an_approved_copy(): void
    {
        $payload = [
            'template' => QuotationTemplateCatalog::LUANGPHO_THAWEESAK,
            'mode' => 'original',
            'document' => ['number' => 'AV-1', 'date' => '2026-10-14', 'recipient' => 'โรงพยาบาลทดสอบ', 'salesperson' => 'ฝ่ายขาย', 'contact' => 'คุณฝ่ายขาย โทร.0812345678'],
            'items' => [[
                'sku' => '0112-20', 'description' => 'GASTRO TUBE', 'origin_details' => "ชื่อผู้ผลิต AVENT\nประเทศที่ผลิต United Mexican states.",
                'quantity' => 1, 'unit' => 'EA', 'pack' => '1 EA', 'unit_price' => 100, 'discount' => 0,
            ]],
            'terms' => [],
        ];
        $documents = new DocumentService(new ThaiBahtText);
        $draft = $documents->quotationEditorPdf($payload, true);
        $signed = $documents->quotationEditorPdf($payload, true, ['name' => 'ผู้อนุมัติ ทดสอบ', 'signature_image' => 'iVBORw0KGgo=']);

        $this->assertStringContainsString('ประเทศที่ผลิต United Mexican states.', $draft);
        $this->assertStringContainsString('คุณฝ่ายขาย โทร.0812345678', $draft);
        $this->assertStringNotContainsString('ผู้อนุมัติ ทดสอบ', $draft);
        $this->assertStringContainsString('ผู้อนุมัติ ทดสอบ', $signed);
        $this->assertStringContainsString('ผู้มีอำนาจอนุมัติของบริษัท', $signed);
        $this->assertStringContainsString('data:image/png;base64,iVBORw0KGgo=', $signed);
        $this->assertLessThan(strpos($signed, 'data:image/png;base64,iVBORw0KGgo='), strpos($signed, 'ขอแสดงความนับถือ'));
        $this->assertLessThan(strpos($signed, 'ผู้อนุมัติ ทดสอบ'), strpos($signed, 'data:image/png;base64,iVBORw0KGgo='));
    }

    #[Test]
    public function it_renders_all_quotation_modes_from_the_latest_draft_without_changing_the_quotation_number(): void
    {
        $quotation = new Quotation([
            'qt_no' => 'QT-TEST-0002', 'qt_date' => '2026-08-01', 'buyer_name' => 'Original Hospital',
            'bplus_customer_code' => 'AR002', 'address_note' => 'Original address', 'total_amount' => 1000,
            'sale_discount_total' => 0, 'created_by' => 'sale-test',
        ]);
        $quotation->qt_id = 2;
        $quotation->setRelation('items', new Collection);

        $draft = new BillingRecord([
            'bill_date' => '2026-08-07', 'buyer_name' => 'Updated Hospital', 'bplus_customer_code' => 'AR002',
            'address_note' => 'Updated address', 'total_amount' => 900, 'sale_discount_total' => 100,
            'created_by' => 'sale-test',
        ]);
        $draft->bill_id = 22;
        $draft->setRelation('items', new Collection([
            new BillItem([
                'item_id' => 220, 'main_category' => 'SKU002', 'sub_category' => 'System product name',
                'customer_product_name' => 'Hospital product name', 'unit_type' => 'piece', 'quantity' => 1,
                'unit_price' => 1000, 'sale_discount_unit' => 0, 'line_total_before_discount' => 1000,
                'line_total' => 1000,
            ]),
        ]));

        $documents = new DocumentService(new ThaiBahtText);
        $original = $documents->quotationPdf($quotation, draft: $draft);
        $copy = $documents->quotationPdf($quotation, copy: true, draft: $draft);
        $revised = $documents->quotationPdf($quotation, draft: $draft, revised: true);

        $this->assertStringStartsWith('%PDF-', $original);
        $this->assertStringStartsWith('%PDF-', $copy);
        $this->assertStringStartsWith('%PDF-', $revised);
        $this->assertSame('System product name', $draft->items->first()->sub_category);
        $this->assertSame('Hospital product name', $draft->items->first()->customer_product_name);
        $this->assertSame('REVISED_Quotation_Original Hospital_2026-08-01.pdf', $documents->quotationDownloadName($quotation, revised: true));
    }

    #[Test]
    public function it_uses_the_customer_and_document_date_for_tax_invoice_downloads(): void
    {
        $bill = new BillingRecord([
            'bill_date' => '2026-07-13',
            'buyer_name' => 'ชื่อลูกค้าเดิม',
            'tax_cust_name' => 'โรงพยาบาล ตากสิน',
        ]);
        $documents = new DocumentService(new ThaiBahtText);

        $this->assertSame('TaxInvoice_โรงพยาบาล ตากสิน_2026-07-13.pdf', $documents->billDownloadName($bill));
        $this->assertSame('COPY_TaxInvoice_โรงพยาบาล ตากสิน_2026-07-13.pdf', $documents->billDownloadName($bill, copy: true));
    }

    #[Test]
    public function it_combines_selected_quotations_as_consecutive_pages(): void
    {
        $bills = collect([1, 2])->map(function (int $id): BillingRecord {
            $quotation = new Quotation([
                'qt_no' => 'QT-TEST-000'.$id,
                'qt_date' => '2026-08-31',
                'buyer_name' => 'Hospital '.$id,
                'created_by' => 'sale-test',
                'total_amount' => 1000,
            ]);
            $quotation->qt_id = $id;
            $quotation->setRelation('creator', new User(['department' => 'VH00'.$id]));
            $quotation->setRelation('items', collect());

            $bill = new BillingRecord([
                'bill_date' => '2026-08-31',
                'buyer_name' => 'Hospital '.$id,
                'created_by' => 'sale-test',
                'total_amount' => 1000,
            ]);
            $bill->bill_id = $id;
            $bill->setRelation('quotation', $quotation);
            $bill->setRelation('items', collect());
            $bill->setRelation('quotationDocumentVersion', new QuotationDocumentVersion([
                'template_id' => QuotationTemplateCatalog::STANDARD,
            ]));

            return $bill;
        });

        $html = (new DocumentService(new ThaiBahtText))->quotationBatchPdf($bills, htmlOnly: true);

        $this->assertSame(2, substr_count($html, '<section class="batch-document">'));
        $this->assertStringContainsString('QTTEST0001', $html);
        $this->assertStringContainsString('QTTEST0002', $html);
        $this->assertStringContainsString('page-break-after:always', $html);
    }

    #[Test]
    public function it_marks_a_declined_quotation_with_a_cross_watermark(): void
    {
        $quotation = new Quotation([
            'qt_no' => 'QT-DECLINED-0001', 'qt_date' => '2026-08-31', 'buyer_name' => 'Declined Hospital',
            'created_by' => 'sale-test', 'total_amount' => 1000,
        ]);
        $quotation->qt_id = 99;
        $quotation->setRelation('creator', new User(['department' => 'VH001']));
        $quotation->setRelation('items', collect());
        $bill = new BillingRecord([
            'bill_date' => '2026-08-31', 'buyer_name' => 'Declined Hospital', 'created_by' => 'sale-test',
            'total_amount' => 1000, 'status' => 'ไม่อนุมัติ',
        ]);
        $bill->setRelation('items', collect());

        $html = (new DocumentService(new ThaiBahtText))->quotationPdf($quotation, draft: $bill, htmlOnly: true);

        $this->assertSame(2, substr_count($html, 'rejection-cross rejection-cross--'));
        $this->assertStringContainsString('ไม่อนุมัติ', $html);
    }

    #[Test]
    public function it_generates_the_same_bplus_columns_as_streamlit(): void
    {
        $bill = new BillingRecord([
            'bill_date' => '2026-07-22', 'bill_id' => 99, 'tax_invoice_no' => 'VHB260700001',
            'bplus_customer_code' => 'AR001', 'sale_discount_total' => 10, 'admin_branch' => 'VH000',
            'admin_dept' => 'VH001', 'admin_project' => 'VH002', 'bplus_salesman_code' => 'S001', 'bill_note' => 'ทดสอบ',
            'bplus_sale_type_code' => 'เงินสด', 'admin_discount_final' => 12,
        ]);
        $bill->bill_id = 99;
        $bill->setRelation('creator', new User(['username' => 'sale-test', 'department' => 'AVANOS']));
        $bill->setRelation('items', new Collection([
            new BillItem(['main_category' => 'SKU001', 'quantity' => 2, 'unit_price' => 500, 'sale_discount_unit' => 5, 'admin_disocount_unit' => 7, 'admin_weight_unit' => 1.2, 'admin_lot' => 'LOT1', 'admin_serial' => 'SN1', 'admin_storage' => 'WH-MAIN']),
        ]));

        $directory = storage_path('app/private/exports');
        File::ensureDirectoryExists($directory);
        $existingTemporaryFiles = File::glob($directory.DIRECTORY_SEPARATOR.'bplus-*');

        $bytes = (new DocumentService(new ThaiBahtText))->bplusWorkbook(new Collection([$bill]));

        $this->assertStringStartsWith('PK', $bytes);
        $this->assertSame($existingTemporaryFiles, File::glob($directory.DIRECTORY_SEPARATOR.'bplus-*'));

        $path = tempnam($directory, 'bplus-test-');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, $bytes);
            $sheet = IOFactory::load($path)->getActiveSheet();

            $this->assertSame('วันที่เอกสาร', $sheet->getCell('A1')->getValue());
            $this->assertSame('รหัสซื้อขาย', $sheet->getCell('L1')->getValue());
            $this->assertSame('SKU001', $sheet->getCell('L2')->getValue());
            $this->assertNull($sheet->getCell('F2')->getValue());
            $this->assertSame('AVANOS', $sheet->getCell('G2')->getValue());
            $this->assertSame('AVANOS', $sheet->getCell('H2')->getValue());
            $this->assertSame('AVANOS', $sheet->getCell('I2')->getValue());
            $this->assertSame('S001', $sheet->getCell('J2')->getValue());
            $this->assertEquals(7, $sheet->getCell('P2')->getValue());
            $this->assertNull($sheet->getCell('Q2')->getValue());
            $this->assertNull($sheet->getCell('R2')->getValue());
            $this->assertNull($sheet->getCell('S2')->getValue());
            $this->assertNull($sheet->getCell('T2')->getValue());
            $review = IOFactory::load($path)->getSheetByName('ข้อมูลตรวจสอบ');
            $this->assertNotNull($review);
            $this->assertSame('เงินสด', $review->getCell('B2')->getValue());
            $this->assertEquals(12, $review->getCell('F2')->getValue());
            $this->assertEquals(7, $review->getCell('I2')->getValue());
        } finally {
            File::delete($path);
        }
    }

    #[Test]
    public function it_generates_a_reconciled_sales_report_and_preserves_identifiers_as_text(): void
    {
        $completedBill = new BillingRecord([
            'bill_date' => '2026-07-13',
            'issued_at' => '2026-07-14 09:30:00',
            'tax_invoice_no' => 'VH260700001',
            'buyer_name' => 'โรงพยาบาล ตากสิน',
            'tax_id' => '0105558041179',
            'created_by' => 'sale-a',
            'status' => 'รอแอดมินส่งออก',
            'sale_discount_total' => 100,
            'admin_discount_final' => 25,
            'bplus_sale_type_code' => 'สินเชื่อ',
            'total_amount' => 800,
        ]);
        $completedBill->bill_id = 101;
        $completedBill->setRelation('items', new Collection([
            new BillItem([
                'main_category' => '00123',
                'sub_category' => 'สินค้าทดสอบ',
                'unit_type' => 'ชิ้น',
                'quantity' => 2,
                'unit_price' => 500,
                'line_total_before_discount' => 1000,
                'line_total' => 900,
                'admin_disocount_unit' => 4,
            ]),
        ]));

        $draftBill = new BillingRecord([
            'bill_date' => '2026-07-20',
            'buyer_name' => 'ลูกค้าฉบับร่าง',
            'tax_id' => '0999999999999',
            'created_by' => 'sale-b',
            'status' => 'ฉบับร่าง',
            'sale_discount_total' => 0,
            'total_amount' => 200,
        ]);
        $draftBill->bill_id = 102;
        $draftBill->setRelation('items', new Collection([
            new BillItem([
                'main_category' => '00999',
                'sub_category' => 'สินค้ายังไม่ส่งอนุมัติ',
                'unit_type' => 'ชิ้น',
                'quantity' => 1,
                'unit_price' => 200,
                'line_total_before_discount' => 200,
                'line_total' => 200,
            ]),
        ]));

        $directory = storage_path('app/private/exports');
        File::ensureDirectoryExists($directory);
        $existingTemporaryFiles = File::glob($directory.DIRECTORY_SEPARATOR.'sales-report-*');
        $documents = new DocumentService(new ThaiBahtText);

        $bytes = $documents->salesReportWorkbook(
            new Collection([$completedBill, $draftBill]),
            ['date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'generated_by' => 'approver-test'],
        );

        $this->assertStringStartsWith('PK', $bytes);
        $this->assertSame($existingTemporaryFiles, File::glob($directory.DIRECTORY_SEPARATOR.'sales-report-*'));

        $path = tempnam($directory, 'sales-report-test-');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, $bytes);
            $workbook = IOFactory::load($path);

            $this->assertSame([
                'ภาพรวม',
                'ประวัติบิล',
                'รายละเอียดการขาย',
                'ยอดขายตาม Sale',
                'Sale x ลูกค้า',
                'ลูกค้า',
                'ลูกค้า x สินค้า',
                'สินค้า',
                'รายเดือน',
            ], $workbook->getSheetNames());

            $summary = $workbook->getSheetByName('ภาพรวม');
            $this->assertNotNull($summary);
            $this->assertSame(2, $summary->getCell('B4')->getValue());
            $this->assertSame(1, $summary->getCell('D4')->getValue());
            $this->assertEquals(1000, $summary->getCell('B5')->getValue());
            $this->assertEquals(200, $summary->getCell('D5')->getValue());
            $this->assertEquals(800, $summary->getCell('F5')->getValue());
            $this->assertEqualsWithDelta(0.2, $summary->getCell('H5')->getCalculatedValue(), 0.00001);

            $history = $workbook->getSheetByName('ประวัติบิล');
            $this->assertNotNull($history);
            $this->assertSame(3, $history->getHighestDataRow());
            $this->assertSame('0105558041179', $history->getCell('F2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $history->getCell('F2')->getDataType());
            $this->assertSame('สินเชื่อ', $history->getCell('R2')->getValue());
            $this->assertEquals(25, $history->getCell('S2')->getValue());

            $detail = $workbook->getSheetByName('รายละเอียดการขาย');
            $this->assertNotNull($detail);
            $this->assertSame('00123', $detail->getCell('I2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $detail->getCell('I2')->getDataType());
            $this->assertSame('สินเชื่อ', $detail->getCell('V2')->getValue());
            $this->assertEquals(4, $detail->getCell('W2')->getValue());
            $this->assertEquals(25, $detail->getCell('X2')->getValue());
            $this->assertEquals(4, $detail->getCell('Y2')->getValue());
            $this->assertEquals(25, $detail->getCell('Z2')->getValue());

            $product = $workbook->getSheetByName('สินค้า');
            $this->assertNotNull($product);
            $this->assertSame(2, $product->getHighestDataRow());
            $this->assertSame('00123', $product->getCell('A2')->getValue());
            $this->assertEquals(800, $product->getCell('K2')->getValue());
        } finally {
            File::delete($path);
        }
    }
}
