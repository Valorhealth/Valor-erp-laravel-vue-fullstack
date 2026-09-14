<?php

namespace Tests\Unit;

use App\Services\SalesCalls\SalesCallImportService;
use App\Services\SalesCalls\SalesCallReportService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SalesCallImportServiceTest extends TestCase
{
    #[Test]
    public function it_reads_daily_merged_layout_and_flat_multi_day_layout(): void
    {
        $path = $this->workbook(function (Spreadsheet $book): void {
            $daily = $book->getActiveSheet();
            $daily->setTitle('010526');
            $daily->setCellValue('A5', 'Call No.')->setCellValue('B5', 'CUSTOMER NAME')
                ->setCellValue('E5', 'SPECIALTY')->setCellValue('G5', 'PLACE OF WORK')
                ->setCellValue('J5', 'PRODUCT-1')->setCellValue('K5', 'CALL OBECTIVE')
                ->setCellValue('M5', 'CALL OUTCOME')->setCellValue('O5', 'F/U DATE')
                ->setCellValue('P5', 'NOTE')->setCellValue('R5', 'SM Comment');
            $daily->setCellValue('A6', 'Example')->setCellValue('B6', 'ต้องข้ามแถวนี้');
            $daily->setCellValue('A7', 1)->setCellValue('B7', 'คุณเอ')->setCellValue('G7', 'โรงพยาบาลหนึ่ง')
                ->setCellValue('E7', 'Surgery')
                ->setCellValue('J7', 'AVANOS DH')->setCellValue('K7', 'Sales')->setCellValue('L7', 'เสนอสินค้า')
                ->setCellValue('M7', 'Success')->setCellValue('N7', 'ลูกค้าสนใจ');

            $flat = $book->createSheet();
            $flat->setTitle('1-15 May2026');
            $flat->fromArray(['Call No.', 'Date', 'PLACE OF WORK', 'CUSTOMER NAME', 'SPECIALTY', 'DEPARTMENT', 'GROUP', 'PRODUCT-1', 'CALL OBJECTIVE', 'CALL DETAIL (Obj)', 'CALL OUTCOME', 'CALL DETAIL (Out)', 'F/U DATE', 'NOTE', 'SM Comment', 'Status'], null, 'A8');
            $flat->fromArray([1, '2026-05-02', 'โรงพยาบาลสอง', 'คุณบี', 'Surgery', 'OPD', 'Doctor', 'FIBROSCAN', 'Awareness', 'แนะนำสินค้า', 'Success', 'นัดหมายแล้ว', null, null, null, 'ทำงาน'], null, 'A9');
        });

        try {
            $parsed = $this->service()->parse(new UploadedFile($path, 'calls.xlsx', null, null, true), new SalesCallReportService);

            $this->assertSame(2, $parsed['summary']['total_rows']);
            $this->assertSame('2026-05-01', $parsed['rows'][0]['activity_date']);
            $this->assertSame('AVANOS-DH', $parsed['rows'][0]['product_1']);
            $this->assertSame('เสนอสินค้า', $parsed['rows'][0]['call_detail_objective']);
            $this->assertSame('ลูกค้าสนใจ', $parsed['rows'][0]['call_detail_outcome']);
            $this->assertSame('2026-05-02', $parsed['rows'][1]['activity_date']);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function it_corrects_an_outlier_daily_sheet_to_the_dominant_workbook_month(): void
    {
        $path = $this->workbook(function (Spreadsheet $book): void {
            foreach (['010526', '020526', '030526', '040526', '050516'] as $index => $title) {
                $sheet = $index === 0 ? $book->getActiveSheet() : $book->createSheet();
                $sheet->setTitle($title);
                $sheet->fromArray(['Call No.', 'CUSTOMER NAME', 'PLACE OF WORK', 'PRODUCT-1', 'SPECIALTY'], null, 'A1');
                $sheet->fromArray([1, 'ลูกค้า '.$index, 'โรงพยาบาล '.$index, 'FIBROSCAN', 'Surgery'], null, 'A2');
            }
        });

        try {
            $parsed = $this->service()->parse(new UploadedFile($path, 'monthly.xlsx', null, null, true), new SalesCallReportService);

            $this->assertSame('2026-05-01', $parsed['summary']['date_from']);
            $this->assertSame('2026-05-05', $parsed['summary']['date_to']);
            $this->assertStringContainsString('จึงปรับเป็น 2026-05-05', implode(' ', $parsed['warnings']));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function it_counts_calls_by_specialty_and_carries_the_previous_date_forward(): void
    {
        $path = $this->workbook(function (Spreadsheet $book): void {
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('Call plan');
            $sheet->fromArray(['Call No.', 'Date', 'PLACE OF WORK', 'CUSTOMER NAME', 'SPECIALTY', 'DEPARTMENT', 'GROUP', 'PRODUCT-1', 'CALL OBJECTIVE', 'CALL OUTCOME'], null, 'A1');
            $sheet->fromArray([1, '2026-05-10', 'รพ.หนึ่ง', 'คุณเอ', 'Surgery', 'OR', 'Doctor', 'FIBROSCAN', 'Sales', 'Success'], null, 'A2');
            $sheet->fromArray([2, null, 'รพ.สอง', null, 'Pediatric', 'OPD', 'Nurse', null, 'Follow Up', null], null, 'A3');
            $sheet->fromArray([3, '2026-05-11', 'ข้อความที่ไม่ใช่ Call', null, null, null, null, null, null, null], null, 'A4');
        });

        try {
            $parsed = $this->service()->parse(new UploadedFile($path, 'calls.xlsx', null, null, true), new SalesCallReportService);

            $this->assertSame(3, $parsed['summary']['total_rows']);
            $this->assertSame(2, $parsed['summary']['counted_calls']);
            $this->assertSame(['2026-05-10', '2026-05-10', '2026-05-11'], array_column($parsed['rows'], 'activity_date'));
            $this->assertSame(['Success', '', ''], array_column($parsed['rows'], 'outcome'));
            $this->assertSame(1, $parsed['quality']['skipped_without_specialty']);
            $this->assertContains('PRODUCT-1', array_column($parsed['quality']['blank_important_fields'], 'label'));
            $this->assertSame(1, $parsed['quality']['invalid_required_rows_count']);
            $this->assertSame('2026-05-10', $parsed['quality']['invalid_required_rows'][0]['date']);
            $this->assertSame(3, $parsed['quality']['invalid_required_rows'][0]['row']);
            $this->assertSame(['CUSTOMER NAME', 'PRODUCT-1', 'CALL OUTCOME'], $parsed['quality']['invalid_required_rows'][0]['missing_fields']);
            $this->assertStringContainsString('จะไม่ตีความเป็น No Call', implode(' ', $parsed['warnings']));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function it_keeps_work_activities_but_excludes_them_and_no_call_from_call_count(): void
    {
        $path = $this->workbook(function (Spreadsheet $book): void {
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('Activities');
            $sheet->fromArray(['Date', 'PLACE OF WORK', 'CUSTOMER NAME', 'SPECIALTY', 'DEPARTMENT', 'GROUP', 'PRODUCT-1', 'CALL OBJECTIVE', 'CALL OUTCOME', 'Status'], null, 'A1');
            $sheet->fromArray(['2026-08-01', 'รพ.หนึ่ง', 'คุณเอ', 'Surgery', 'OR', 'Doctor', 'FIBROSCAN', 'Sales', 'Success', 'ทำงาน'], null, 'A2');
            $sheet->fromArray(['2026-08-02', 'รพ.สอง', 'คุณบี', 'Pediatric', 'OPD', 'Nurse', 'FIBROSCAN', 'Follow Up', 'No Call', 'ทำงาน'], null, 'A3');
            $sheet->fromArray(['2026-08-03', 'ประชุมบริษัท', null, null, null, null, null, null, null, 'ทำงาน'], null, 'A4');
        });

        try {
            $parsed = $this->service()->parse(new UploadedFile($path, 'activities.xlsx', null, null, true), new SalesCallReportService);

            $this->assertSame(3, $parsed['summary']['total_rows']);
            $this->assertSame(1, $parsed['summary']['counted_calls']);
            $this->assertSame(1, $parsed['quality']['skipped_without_specialty']);
            $this->assertSame(0, $parsed['quality']['invalid_required_rows_count']);
            $this->assertSame(['ทำงาน', 'ทำงาน', 'ทำงาน'], array_column($parsed['rows'], 'status'));
        } finally {
            @unlink($path);
        }
    }

    private function service(): SalesCallImportService
    {
        return new SalesCallImportService;
    }

    /** @param callable(Spreadsheet): void $build */
    private function workbook(callable $build): string
    {
        $book = new Spreadsheet;
        $build($book);
        $path = tempnam(sys_get_temp_dir(), 'sales-call-import-').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
