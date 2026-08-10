<?php

namespace Tests\Unit;

use App\Models\BillingRecord;
use App\Models\BillItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Services\Documents\DocumentService;
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
    public function it_generates_the_same_bplus_columns_as_streamlit(): void
    {
        $bill = new BillingRecord([
            'bill_date' => '2026-07-22', 'bill_id' => 99, 'tax_invoice_no' => 'VHB260700001',
            'bplus_customer_code' => 'AR001', 'sale_discount_total' => 10, 'admin_branch' => 'VH000',
            'admin_dept' => 'VH001', 'admin_project' => 'VH002', 'bplus_salesman_code' => 'S001', 'bill_note' => 'ทดสอบ',
            'bplus_sale_type_code' => 'เงินสด', 'admin_discount_final' => 12,
        ]);
        $bill->bill_id = 99;
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
            $this->assertEquals(12, $sheet->getCell('F2')->getValue());
            $this->assertEquals(7, $sheet->getCell('P2')->getValue());
            $this->assertSame('WH-MAIN', $sheet->getCell('T2')->getValue());
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
            'status' => 'เตรียมส่งออก',
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
