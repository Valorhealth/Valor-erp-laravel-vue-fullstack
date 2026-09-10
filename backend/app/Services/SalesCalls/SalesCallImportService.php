<?php

namespace App\Services\SalesCalls;

use App\Models\SalesCallActivity;
use App\Models\User;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

final class SalesCallImportService
{
    private const MAX_SHEETS = 64;

    private const MAX_ROWS = 5000;

    /** @var array<string, string> */
    private const HEADER_ALIASES = [
        'CALLNO' => 'call_no', 'CALLNUMBER' => 'call_no', 'NO' => 'call_no', 'ลำดับ' => 'call_no',
        'DATE' => 'activity_date', 'CALLDATE' => 'activity_date', 'วันที่' => 'activity_date',
        'PLACEOFWORK' => 'place_of_work', 'WORKPLACE' => 'place_of_work', 'HOSPITAL' => 'place_of_work', 'HOSPITALNAME' => 'place_of_work', 'สถานที่ทำงาน' => 'place_of_work', 'โรงพยาบาล' => 'place_of_work',
        'CUSTOMERNAME' => 'customer_name', 'CUSTOMER' => 'customer_name', 'CONTACTNAME' => 'customer_name', 'ชื่อลูกค้า' => 'customer_name',
        'SPECIALTY' => 'specialty', 'SPECIATY' => 'specialty', 'SPECIALITY' => 'specialty', 'แผนกแพทย์' => 'specialty',
        'DEPARTMENT' => 'department', 'DEPT' => 'department', 'หน่วยงาน' => 'department',
        'GROUP' => 'customer_group', 'CUSTOMERGROUP' => 'customer_group', 'กลุ่ม' => 'customer_group',
        'PRODUCT1' => 'product_1', 'PRODUCT' => 'product_1', 'PRODUCTINTEREST' => 'product_1', 'สินค้า' => 'product_1',
        'CALLOBJECTIVE' => 'objective', 'CALLOBECTIVE' => 'objective', 'OBJECTIVE' => 'objective', 'วัตถุประสงค์' => 'objective',
        'CALLDETAILOBJ' => 'call_detail_objective', 'CALLDETAILOBJECTIVE' => 'call_detail_objective', 'OBJECTIVEDETAIL' => 'call_detail_objective', 'รายละเอียดวัตถุประสงค์' => 'call_detail_objective',
        'CALLOUTCOME' => 'outcome', 'OUTCOME' => 'outcome', 'ผลลัพธ์' => 'outcome',
        'CALLDETAILOUT' => 'call_detail_outcome', 'CALLDETAILOUTCOME' => 'call_detail_outcome', 'OUTCOMEDETAIL' => 'call_detail_outcome', 'รายละเอียดผลลัพธ์' => 'call_detail_outcome',
        'FUDATE' => 'next_follow_up_date', 'FOLLOWUPDATE' => 'next_follow_up_date', 'NEXTFOLLOWUPDATE' => 'next_follow_up_date', 'วันที่ติดตาม' => 'next_follow_up_date',
        'NOTE' => 'notes', 'NOTES' => 'notes', 'REMARK' => 'notes', 'หมายเหตุ' => 'notes',
        'SMCOMMENT' => 'sm_comment', 'MANAGERCOMMENT' => 'sm_comment', 'ความคิดเห็นหัวหน้า' => 'sm_comment',
        'STATUS' => 'status', 'สถานะ' => 'status',
    ];

    /** @return array{summary: array<string, mixed>, preview: array<int, array<string, mixed>>, warnings: array<int, string>, rows: array<int, array<string, mixed>>} */
    public function parse(UploadedFile $file, SalesCallReportService $reports): array
    {
        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getRealPath());
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => 'ไม่สามารถอ่านไฟล์ Excel ได้ กรุณาตรวจสอบว่าไฟล์ไม่เสียหายและเป็น .xlsx หรือ .xls',
            ]);
        }

        try {
            $worksheets = $spreadsheet->getWorksheetIterator();
            $rows = [];
            $warnings = [];
            $usedSheets = 0;
            $scannedSheets = 0;
            $dominantSheetMonth = $this->dominantSheetMonth($spreadsheet->getAllSheets());

            foreach ($worksheets as $sheet) {
                $scannedSheets++;
                if ($scannedSheets > self::MAX_SHEETS) {
                    $warnings['sheet-limit'] = 'อ่านสูงสุด '.self::MAX_SHEETS.' ชีต ส่วนชีตที่เหลือถูกข้าม';
                    break;
                }

                $header = $this->findHeader($sheet);
                if ($header === null) {
                    continue;
                }

                $usedSheets++;
                $sheetDate = $this->dateFromSheetName($sheet->getTitle());
                if ($sheetDate !== null && $dominantSheetMonth !== null && ! str_starts_with($sheetDate, $dominantSheetMonth.'-')) {
                    $day = (int) substr($sheetDate, 8, 2);
                    $correctedDate = $dominantSheetMonth.'-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                    if ($this->parseDate($correctedDate) !== null) {
                        $warnings['corrected-sheet-date-'.$sheet->getTitle()] = "ชีต {$sheet->getTitle()}: วันที่จากชื่อชีต {$sheetDate} ต่างจากเดือนหลัก จึงปรับเป็น {$correctedDate}";
                        $sheetDate = $correctedDate;
                    }
                }
                foreach ($this->rowsFromSheet($sheet, $header, $sheetDate, $reports, $warnings) as $row) {
                    $rows[] = $row;
                    if (count($rows) >= self::MAX_ROWS) {
                        $warnings['row-limit'] = 'อ่านสูงสุด '.number_format(self::MAX_ROWS).' รายการต่อไฟล์ ส่วนรายการที่เหลือถูกข้าม';
                        break 2;
                    }
                }
            }

            usort($rows, fn (array $left, array $right): int => [$left['activity_date'], $left['_source_sheet'], $left['_source_row']] <=> [$right['activity_date'], $right['_source_sheet'], $right['_source_row']]);
            $dates = array_values(array_unique(array_column($rows, 'activity_date')));
            $preview = array_map(function (array $row): array {
                unset($row['_source_sheet'], $row['_source_row']);

                return $row;
            }, array_slice($rows, 0, 150));

            return [
                'summary' => [
                    'file_name' => $file->getClientOriginalName(),
                    'sheets_scanned' => $scannedSheets,
                    'sheets_used' => $usedSheets,
                    'total_rows' => count($rows),
                    'dates_count' => count($dates),
                    'date_from' => $dates[0] ?? null,
                    'date_to' => $dates === [] ? null : $dates[array_key_last($dates)],
                    'preview_rows' => count($preview),
                ],
                'preview' => $preview,
                'warnings' => array_values($warnings),
                'rows' => $rows,
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** @param array<int, array<string, mixed>> $rows @return array{imported_rows: int, imported_dates: int, replaced_rows: int, date_from: string|null, date_to: string|null} */
    public function replaceUserDates(User $user, array $rows): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'ไม่พบข้อมูล Call Sale ที่สามารถนำเข้าได้',
            ]);
        }

        $grouped = collect($rows)->groupBy('activity_date')->sortKeys();

        return DB::transaction(function () use ($user, $grouped): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sales-call-import-'.$user->id]);
            $dates = $grouped->keys()->values()->all();
            $replacedRows = SalesCallActivity::query()
                ->where('seller_user_id', $user->id)
                ->whereIn('activity_date', $dates)
                ->count();

            SalesCallActivity::query()
                ->where('seller_user_id', $user->id)
                ->whereIn('activity_date', $dates)
                ->delete();

            $now = now();
            $records = [];
            foreach ($grouped as $date => $dayRows) {
                foreach ($dayRows->values() as $index => $row) {
                    $records[] = [
                        'seller_user_id' => $user->id,
                        'seller_username' => $user->username,
                        'call_no' => $index + 1,
                        'activity_date' => $date,
                        'activity_type' => 'เข้าพบ',
                        'place_of_work' => $row['place_of_work'],
                        'customer_name' => $row['customer_name'],
                        'specialty' => $row['specialty'],
                        'department' => $row['department'],
                        'customer_group' => $row['customer_group'],
                        'product_1' => $row['product_1'],
                        'product_interest' => $row['product_1'],
                        'objective' => $row['objective'],
                        'call_detail_objective' => $row['call_detail_objective'],
                        'outcome' => $row['outcome'],
                        'call_detail_outcome' => $row['call_detail_outcome'],
                        'opportunity_stage' => $this->stageFor($row['objective'], $row['outcome']),
                        'next_follow_up_date' => $row['next_follow_up_date'],
                        'next_action' => $row['call_detail_outcome'],
                        'notes' => $row['notes'],
                        'sm_comment' => $row['sm_comment'],
                        'status' => $row['status'],
                        'estimated_value' => 0,
                        'actual_value' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('sales_call_activities')->insert($chunk);
            }

            return [
                'imported_rows' => count($records),
                'imported_dates' => count($dates),
                'replaced_rows' => $replacedRows,
                'date_from' => $dates[0] ?? null,
                'date_to' => $dates === [] ? null : $dates[array_key_last($dates)],
            ];
        });
    }

    /** @return array{row: int, columns: array<string, int>}|null */
    private function findHeader(Worksheet $sheet): ?array
    {
        $best = null;
        $bestScore = 0;
        $maxColumn = min(Coordinate::columnIndexFromString($sheet->getHighestColumn()), 80);

        for ($row = 1; $row <= min($sheet->getHighestRow(), 50); $row++) {
            $columns = [];
            for ($column = 1; $column <= $maxColumn; $column++) {
                $field = self::HEADER_ALIASES[$this->normalizeHeader($sheet->getCell([$column, $row])->getValue())] ?? null;
                if ($field !== null && ! isset($columns[$field])) {
                    $columns[$field] = $column;
                }
            }

            $score = count($columns);
            if ($score > $bestScore && isset($columns['call_no']) && (isset($columns['customer_name']) || isset($columns['place_of_work']))) {
                $best = ['row' => $row, 'columns' => $columns];
                $bestScore = $score;
            }
        }

        if ($bestScore < 4 || $best === null) {
            return null;
        }

        $columns = $best['columns'];
        if (! isset($columns['call_detail_objective'], $columns['outcome']) && isset($columns['objective']) && ($columns['outcome'] ?? 0) === $columns['objective'] + 2) {
            $columns['call_detail_objective'] = $columns['objective'] + 1;
        }
        if (! isset($columns['call_detail_outcome']) && isset($columns['outcome'])) {
            $nextKnownColumn = collect($columns)->filter(fn (int $column): bool => $column > $columns['outcome'])->min();
            if ($nextKnownColumn === null || $nextKnownColumn > $columns['outcome'] + 1) {
                $columns['call_detail_outcome'] = $columns['outcome'] + 1;
            }
        }

        return ['row' => $best['row'], 'columns' => $columns];
    }

    /** @param array{row: int, columns: array<string, int>} $header @param array<string, string> $warnings @return array<int, array<string, mixed>> */
    private function rowsFromSheet(Worksheet $sheet, array $header, ?string $sheetDate, SalesCallReportService $reports, array &$warnings): array
    {
        $rows = [];
        $lastRow = min($sheet->getHighestDataRow(), $header['row'] + 5000);

        for ($rowNumber = $header['row'] + 1; $rowNumber <= $lastRow; $rowNumber++) {
            $raw = [];
            foreach ($header['columns'] as $field => $column) {
                $raw[$field] = $sheet->getCell([$column, $rowNumber])->getCalculatedValue();
            }

            if ($this->normalizeHeader($raw['call_no'] ?? null) === 'EXAMPLE') {
                continue;
            }

            $hasContent = collect($raw)
                ->except(['call_no', 'activity_date'])
                ->contains(fn (mixed $value): bool => $this->text($value) !== null);
            if (! $hasContent) {
                continue;
            }

            $date = $this->parseDate($raw['activity_date'] ?? null) ?? $sheetDate;
            if ($date === null) {
                $warnings['missing-date-'.$sheet->getTitle().'-'.$rowNumber] = "ข้ามชีต {$sheet->getTitle()} แถว {$rowNumber}: ไม่พบวันที่และไม่สามารถอ่านวันที่จากชื่อชีตได้";

                continue;
            }

            $source = $sheet->getTitle().' แถว '.$rowNumber;
            $place = $this->text($raw['place_of_work'] ?? null, 500);
            $status = $this->canonical($raw['status'] ?? null, 'status', $reports, $warnings, $source)
                ?? $this->statusFromText($place);
            $objective = $this->canonical($raw['objective'] ?? null, 'objective', $reports, $warnings, $source) ?? 'Others';
            $outcome = $this->canonical($raw['outcome'] ?? null, 'outcome', $reports, $warnings, $source) ?? 'No Call';

            $rows[] = [
                'activity_date' => $date,
                'place_of_work' => $place,
                'customer_name' => $this->text($raw['customer_name'] ?? null, 500),
                'specialty' => $this->canonical($raw['specialty'] ?? null, 'specialty', $reports, $warnings, $source),
                'department' => $this->canonical($raw['department'] ?? null, 'department', $reports, $warnings, $source),
                'customer_group' => $this->canonical($raw['customer_group'] ?? null, 'group', $reports, $warnings, $source),
                'product_1' => $this->canonical($raw['product_1'] ?? null, 'product', $reports, $warnings, $source),
                'objective' => $objective,
                'call_detail_objective' => $this->text($raw['call_detail_objective'] ?? null, 10000),
                'outcome' => $outcome,
                'call_detail_outcome' => $this->text($raw['call_detail_outcome'] ?? null, 10000),
                'next_follow_up_date' => $this->parseDate($raw['next_follow_up_date'] ?? null),
                'notes' => $this->text($raw['notes'] ?? null, 10000),
                'sm_comment' => $this->text($raw['sm_comment'] ?? null, 10000),
                'status' => $status,
                '_source_sheet' => $sheet->getTitle(),
                '_source_row' => $rowNumber,
            ];
        }

        return $rows;
    }

    /** @param array<string, string> $warnings */
    private function canonical(mixed $value, string $category, SalesCallReportService $reports, array &$warnings, string $source): ?string
    {
        $text = $this->text($value, 255);
        if ($text === null) {
            return null;
        }

        $aliases = [
            'specialty' => ['rehabilitation madicine' => 'Rehabilitation Medicine', 'เลขา-ธุรการ' => 'เลขา / ธุรการ', 'เลขา - ธุรการ' => 'เลขา / ธุรการ'],
            'product' => ['avanos dh' => 'AVANOS-DH', 'avanos-dh' => 'AVANOS-DH', 's-patch ex' => 'S-PATCH'],
            'objective' => ['sale' => 'Sales', 'other' => 'Others'],
            'outcome' => ['successful' => 'Success', 'unsuccessful' => 'Failure', 'nocall' => 'No Call'],
            'status' => ['work' => 'ทำงาน', 'working' => 'ทำงาน', 'leave' => 'ลา', 'holiday' => 'วันหยุดบริษัท'],
        ];
        $lookup = $this->normalizeValue($text);
        $text = $aliases[$category][$lookup] ?? $text;
        $allowed = collect($reports->allowedOptions($category))->keyBy(fn (string $option): string => $this->normalizeValue($option));

        if ($allowed->has($this->normalizeValue($text))) {
            return $allowed->get($this->normalizeValue($text));
        }

        if (in_array($category, ['objective', 'outcome', 'status'], true)) {
            $fallback = ['objective' => 'Others', 'outcome' => 'No Call', 'status' => 'ทำงาน'][$category];
            $warnings['fallback-'.$category.'-'.$lookup] = "{$source}: ค่า {$text} ไม่อยู่ใน Dropdown {$category} จึงใช้ {$fallback}";

            return $fallback;
        }

        $warnings['unknown-'.$category.'-'.$lookup] = "{$source}: ค่า {$text} ยังไม่มีใน Dropdown ส่วนกลาง แต่ระบบจะเก็บค่าต้นฉบับไว้";

        return $text;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value) && (float) $value > 0) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        $text = $this->text($value);
        if ($text === null) {
            return null;
        }
        if (! preg_match('/^(\d{1,4})[\/\-.](\d{1,2})[\/\-.](\d{1,4})$/u', $text, $matches)) {
            return null;
        }

        if (strlen($matches[1]) === 4) {
            [$year, $month, $day] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
        } else {
            [$day, $month, $year] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
        }
        $year = $year > 2400 ? $year - 543 : ($year < 100 ? 2000 + $year : $year);

        return checkdate($month, $day, $year) ? (new DateTimeImmutable)->setDate($year, $month, $day)->format('Y-m-d') : null;
    }

    private function dateFromSheetName(string $name): ?string
    {
        $digits = preg_replace('/\D/u', '', trim($name));
        if (! preg_match('/^(\d{2})(\d{2})(\d{2}|\d{4})$/', (string) $digits, $matches)) {
            return null;
        }

        return $this->parseDate("{$matches[1]}/{$matches[2]}/{$matches[3]}");
    }

    /** @param array<int, Worksheet> $sheets */
    private function dominantSheetMonth(array $sheets): ?string
    {
        $months = collect($sheets)
            ->map(fn (Worksheet $sheet): ?string => $this->dateFromSheetName($sheet->getTitle()))
            ->filter()
            ->map(fn (string $date): string => substr($date, 0, 7));
        if ($months->count() < 5) {
            return null;
        }

        $counts = $months->countBy()->sortDesc();
        $dominant = $counts->keys()->first();

        return $dominant !== null && ((int) $counts->first() / $months->count()) >= 0.7 ? $dominant : null;
    }

    private function statusFromText(?string $place): string
    {
        $text = mb_strtolower((string) $place);
        if (str_contains($text, 'วันหยุด')) {
            return 'วันหยุดบริษัท';
        }
        if (str_contains($text, 'ลาป่วย') || str_contains($text, 'ลาพัก') || preg_match('/(^|\s)ลา($|\s)/u', $text)) {
            return 'ลา';
        }

        return 'ทำงาน';
    }

    private function text(mixed $value, int $maxLength = 10000): ?string
    {
        if ($value === null || is_bool($value)) {
            return null;
        }

        $text = preg_replace('/[\x{00A0}\s]+/u', ' ', trim((string) $value));

        return $text === '' ? null : mb_substr($text, 0, $maxLength);
    }

    private function normalizeHeader(mixed $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper((string) $value)) ?? '';
    }

    private function normalizeValue(string $value): string
    {
        return mb_strtolower(preg_replace('/[\x{00A0}\s]+/u', ' ', trim($value)) ?? $value);
    }

    private function stageFor(string $objective, string $outcome): string
    {
        if ($outcome === 'Failure') {
            return 'แพ้การขาย';
        }
        if ($outcome === 'No Call') {
            return 'ลูกค้าเป้าหมาย';
        }

        return match ($objective) {
            'Sales' => 'ชนะการขาย',
            'Trial', 'Usage' => 'ค้นหาความต้องการ',
            'List' => 'นำเสนอ/เสนอราคา',
            default => 'เริ่มติดต่อ',
        };
    }
}
