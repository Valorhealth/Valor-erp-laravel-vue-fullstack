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
                $sheet->fromArray(['Call No.', 'CUSTOMER NAME', 'PLACE OF WORK', 'PRODUCT-1'], null, 'A1');
                $sheet->fromArray([1, 'ลูกค้า '.$index, 'โรงพยาบาล '.$index, 'FIBROSCAN'], null, 'A2');
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
