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
    private const COLUMN_LABELS = [
        'call_no' => 'Call No.', 'activity_date' => 'Date', 'place_of_work' => 'PLACE OF WORK',
        'customer_name' => 'CUSTOMER NAME', 'specialty' => 'SPECIALTY', 'department' => 'DEPARTMENT',
        'customer_group' => 'GROUP', 'product_1' => 'PRODUCT-1', 'objective' => 'CALL OBJECTIVE',
        'call_detail_objective' => 'CALL DETAIL (Obj)', 'outcome' => 'CALL OUTCOME',
        'call_detail_outcome' => 'CALL DETAIL (Out)', 'next_follow_up_date' => 'F/U DATE',
        'notes' => 'NOTE', 'sm_comment' => 'SM Comment', 'status' => 'Status',
    ];

    /** @var array<string, string> */
    private const IMPORTANT_FIELDS = [
        'place_of_work' => 'PLACE OF WORK', 'customer_name' => 'CUSTOMER NAME',
        'specialty' => 'SPECIALTY', 'department' => 'DEPARTMENT', 'customer_group' => 'GROUP',
        'product_1' => 'PRODUCT-1', 'objective' => 'CALL OBJECTIVE',
        'outcome' => 'CALL OUTCOME', 'status' => 'Status',
    ];

    /** @var array<string, string> */
    private const REQUIRED_CALL_FIELDS = [
        'place_of_work' => 'PLACE OF WORK',
        'customer_name' => 'CUSTOMER NAME',
        'specialty' => 'SPECIALTY',
        'department' => 'DEPARTMENT',
        'customer_group' => 'GROUP',
        'product_1' => 'PRODUCT-1',
        'objective' => 'CALL OBJECTIVE',
        'outcome' => 'CALL OUTCOME',
    ];

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
            $detectedColumns = [];
            $blankImportant = array_fill_keys(array_keys(self::IMPORTANT_FIELDS), 0);
            $skippedWithoutSpecialty = 0;
            $skippedWithoutDate = 0;
            $invalidRequiredRows = [];
            $sheetDateContext = $this->sheetDateContext($spreadsheet->getAllSheets());
            $dominantSheetMonth = $sheetDateContext['dominant_month'];

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
                $detectedColumns = array_values(array_unique([...$detectedColumns, ...array_keys($header['columns'])]));
                $sheetMissing = array_diff(array_keys(self::COLUMN_LABELS), array_keys($header['columns']));
                if ($sheetMissing !== []) {
                    $labels = collect($sheetMissing)->map(fn (string $field): string => self::COLUMN_LABELS[$field])->implode(', ');
                    $warnings['missing-columns-'.$sheet->getTitle()] = "ชีต {$sheet->getTitle()}: ไม่พบคอลัมน์ {$labels}";
                }
                $sheetDate = $sheetDateContext['dates'][$sheet->getTitle()] ?? null;
                $sheetMonth = $this->monthFromSheetName($sheet->getTitle()) ?? $dominantSheetMonth;
                if ($sheetDate !== null && $dominantSheetMonth !== null && ! str_starts_with($sheetDate, $dominantSheetMonth.'-')) {
                    $day = (int) substr($sheetDate, 8, 2);
                    $correctedDate = $dominantSheetMonth.'-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                    if ($this->parseDate($correctedDate) !== null) {
                        $warnings['corrected-sheet-date-'.$sheet->getTitle()] = "ชีต {$sheet->getTitle()}: วันที่จากชื่อชีต {$sheetDate} ต่างจากเดือนหลัก จึงปรับเป็น {$correctedDate}";
                        $sheetDate = $correctedDate;
                    }
                }
                foreach ($this->rowsFromSheet($sheet, $header, $sheetDate, $sheetMonth, $reports, $warnings, $blankImportant, $invalidRequiredRows, $skippedWithoutSpecialty, $skippedWithoutDate) as $row) {
                    $rows[] = $row;
                    if (count($rows) >= self::MAX_ROWS) {
                        $warnings['row-limit'] = 'อ่านสูงสุด '.number_format(self::MAX_ROWS).' รายการต่อไฟล์ ส่วนรายการที่เหลือถูกข้าม';
                        break 2;
                    }
                }
            }

            usort($rows, fn (array $left, array $right): int => [$left['activity_date'], $left['_source_sheet'], $left['_source_row']] <=> [$right['activity_date'], $right['_source_sheet'], $right['_source_row']]);
            $dates = array_values(array_unique(array_column($rows, 'activity_date')));
            $reportMonth = collect($rows)
                ->map(fn (array $row): string => substr($row['activity_date'], 0, 7))
                ->countBy()
                ->sortDesc()
                ->keys()
                ->first();
            $preview = array_map(function (array $row): array {
                unset($row['_source_sheet'], $row['_source_row'], $row['_counts_as_call']);

                return $row;
            }, array_slice($rows, 0, 150));
            $missingColumns = array_values(array_diff(array_keys(self::COLUMN_LABELS), $detectedColumns));
            $blankFields = collect($blankImportant)
                ->filter(fn (int $count): bool => $count > 0)
                ->map(fn (int $count, string $field): array => ['field' => $field, 'label' => self::IMPORTANT_FIELDS[$field], 'count' => $count])
                ->values()
                ->all();

            return [
                'summary' => [
                    'file_name' => $file->getClientOriginalName(),
                    'sheets_scanned' => $scannedSheets,
                    'sheets_used' => $usedSheets,
                    'total_rows' => count($rows),
                    'counted_calls' => count(array_filter($rows, fn (array $row): bool => $row['_counts_as_call'])),
                    'dates_count' => count($dates),
                    'date_from' => $dates[0] ?? null,
                    'date_to' => $dates === [] ? null : $dates[array_key_last($dates)],
                    'report_month' => $reportMonth === null ? null : $reportMonth.'-01',
                    'preview_rows' => count($preview),
                ],
                'quality' => [
                    'detected_columns' => collect($detectedColumns)->map(fn (string $field): string => self::COLUMN_LABELS[$field])->values()->all(),
                    'missing_columns' => collect($missingColumns)->map(fn (string $field): string => self::COLUMN_LABELS[$field])->values()->all(),
                    'blank_important_fields' => $blankFields,
                    'invalid_required_rows' => $invalidRequiredRows,
                    'invalid_required_rows_count' => count($invalidRequiredRows),
                    'skipped_without_specialty' => $skippedWithoutSpecialty,
                    'skipped_without_date' => $skippedWithoutDate,
                ],
                'preview' => $preview,
                'warnings' => array_values($warnings),
                'rows' => $rows,
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** @param array<int, array<string, mixed>> $rows @return array{imported_rows: int, counted_calls: int, imported_dates: int, replaced_rows: int, date_from: string|null, date_to: string|null} */
    public function replaceUserDates(User $user, array $rows): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'ไม่พบข้อมูล Call Sale ที่สามารถนำเข้าได้',
            ]);
        }

        $grouped = collect($rows)->groupBy('activity_date')->sortKeys();
        $countedCalls = collect($rows)->filter(fn (array $row): bool => (bool) ($row['_counts_as_call'] ?? false))->count();

        return DB::transaction(function () use ($user, $grouped, $countedCalls): array {
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
                'counted_calls' => $countedCalls,
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
            if ($score > $bestScore && (isset($columns['call_no']) || isset($columns['specialty'])) && (isset($columns['customer_name']) || isset($columns['place_of_work']))) {
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

    /** @param array{row: int, columns: array<string, int>} $header @param array<string, string> $warnings @param array<string, int> $blankImportant @param array<int, array<string, mixed>> $invalidRequiredRows @return array<int, array<string, mixed>> */
    private function rowsFromSheet(Worksheet $sheet, array $header, ?string $sheetDate, ?string $sheetMonth, SalesCallReportService $reports, array &$warnings, array &$blankImportant, array &$invalidRequiredRows, int &$skippedWithoutSpecialty, int &$skippedWithoutDate): array
    {
        $rows = [];
        $lastRow = min($sheet->getHighestDataRow(), $header['row'] + 5000);
        $currentDate = $sheetDate;

        for ($rowNumber = $header['row'] + 1; $rowNumber <= $lastRow; $rowNumber++) {
            $raw = [];
            foreach ($header['columns'] as $field => $column) {
                $raw[$field] = $sheet->getCell([$column, $rowNumber])->getCalculatedValue();
            }

            if ($this->normalizeHeader($raw['call_no'] ?? null) === 'EXAMPLE') {
                continue;
            }

            $rowDate = $this->parseDate($raw['activity_date'] ?? null);
            if ($rowDate !== null) {
                $correctedDate = $this->correctSwappedDateMonth($rowDate, $sheetMonth);
                if ($correctedDate !== $rowDate) {
                    $warnings['corrected-row-date-'.$sheet->getTitle()] = "ชีต {$sheet->getTitle()}: พบวันที่ Excel สลับวันกับเดือน ระบบปรับวันที่ให้ตรงกับเดือนของชื่อชีตแล้ว";
                    $rowDate = $correctedDate;
                }
                $currentDate = $rowDate;
            }
            $specialtyText = $this->text($raw['specialty'] ?? null, 255);
            // A non-call row represents work only when PLACE OF WORK describes
            // the activity. This avoids importing empty template rows that only
            // contain a default Status value.
            $hasWorkActivity = $this->text($raw['place_of_work'] ?? null, 500) !== null;
            if ($specialtyText === null && ! $hasWorkActivity) {
                continue;
            }

            $date = $currentDate;
            if ($date === null) {
                $warnings['missing-date-'.$sheet->getTitle().'-'.$rowNumber] = "ข้ามชีต {$sheet->getTitle()} แถว {$rowNumber}: ไม่พบวันที่และไม่สามารถอ่านวันที่จากชื่อชีตได้";
                $skippedWithoutDate++;

                continue;
            }

            $source = $sheet->getTitle().' แถว '.$rowNumber;
            if ($specialtyText !== null) {
                $missingRequired = collect(self::REQUIRED_CALL_FIELDS)
                    ->filter(fn (string $label, string $field): bool => $this->text($raw[$field] ?? null) === null)
                    ->values()
                    ->all();
                if ($missingRequired !== []) {
                    $invalidRequiredRows[] = [
                        'date' => $date,
                        'sheet' => $sheet->getTitle(),
                        'row' => $rowNumber,
                        'missing_fields' => $missingRequired,
                    ];
                }
            } else {
                // Keep non-call work rows so Working Day can be calculated from
                // actual activity, while excluding them from every Call KPI.
                $skippedWithoutSpecialty++;
            }
            $place = $this->text($raw['place_of_work'] ?? null, 500);
            $status = $this->canonical($raw['status'] ?? null, 'status', $reports, $warnings, $source)
                ?? $this->statusFromText($place);
            $objective = $this->canonical($raw['objective'] ?? null, 'objective', $reports, $warnings, $source) ?? 'Others';
            $outcome = $this->canonical($raw['outcome'] ?? null, 'outcome', $reports, $warnings, $source) ?? '';
            if ($specialtyText !== null && $outcome === '') {
                $warnings['blank-outcome-'.$sheet->getTitle().'-'.$rowNumber] = "{$source}: CALL OUTCOME ว่าง ระบบเก็บเป็นค่าว่างและจะไม่ตีความเป็น No Call";
            }
            if ($specialtyText !== null) {
                foreach (self::IMPORTANT_FIELDS as $field => $label) {
                    if ($this->text($raw[$field] ?? null) === null) {
                        $blankImportant[$field]++;
                    }
                }
            }

            $specialty = $this->canonical($raw['specialty'] ?? null, 'specialty', $reports, $warnings, $source);

            $rows[] = [
                'activity_date' => $date,
                'place_of_work' => $place,
                'customer_name' => $this->text($raw['customer_name'] ?? null, 500),
                'specialty' => $specialty,
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
                '_counts_as_call' => $specialty !== null && $outcome !== 'No Call',
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

        if (in_array($category, ['objective', 'status'], true)) {
            $fallback = ['objective' => 'Others', 'status' => 'ทำงาน'][$category];
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

    private function dateFromSheetName(string $name, bool $monthFirst = false): ?string
    {
        $digits = preg_replace('/\D/u', '', trim($name));
        if (! preg_match('/^(\d{2})(\d{2})(\d{2}|\d{4})$/', (string) $digits, $matches)) {
            return null;
        }

        $day = $monthFirst ? $matches[2] : $matches[1];
        $month = $monthFirst ? $matches[1] : $matches[2];

        return $this->parseDate("{$day}/{$month}/{$matches[3]}");
    }

    private function monthFromSheetName(string $name): ?string
    {
        $months = [
            'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2,
            'mar' => 3, 'march' => 3, 'apr' => 4, 'april' => 4,
            'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7,
            'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9,
            'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11,
            'dec' => 12, 'december' => 12,
        ];
        if (! preg_match('/\b('.implode('|', array_keys($months)).')\s*(\d{2}|\d{4})\b/iu', $name, $matches)) {
            return null;
        }

        $year = (int) $matches[2];
        $year = $year < 100 ? 2000 + $year : $year;

        return sprintf('%04d-%02d', $year, $months[mb_strtolower($matches[1])]);
    }

    private function correctSwappedDateMonth(string $date, ?string $expectedMonth): string
    {
        if ($expectedMonth === null || str_starts_with($date, $expectedMonth.'-')) {
            return $date;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));
        [$expectedYear, $expectedMonthNumber] = array_map('intval', explode('-', $expectedMonth));
        if ($year !== $expectedYear || $day !== $expectedMonthNumber || ! checkdate($expectedMonthNumber, $month, $year)) {
            return $date;
        }

        return sprintf('%04d-%02d-%02d', $year, $expectedMonthNumber, $month);
    }

    /**
     * Detect whether six-digit daily sheet names use DDMMYY or MMDDYY.
     *
     * @param  array<int, Worksheet>  $sheets
     * @return array{dates: array<string, string>, dominant_month: string|null}
     */
    private function sheetDateContext(array $sheets): array
    {
        $candidates = collect([false, true])->map(function (bool $monthFirst) use ($sheets): array {
            $dates = collect($sheets)->mapWithKeys(function (Worksheet $sheet) use ($monthFirst): array {
                $date = $this->dateFromSheetName($sheet->getTitle(), $monthFirst);

                return $date === null ? [] : [$sheet->getTitle() => $date];
            });
            $monthCounts = $dates->map(fn (string $date): string => substr($date, 0, 7))->countBy()->sortDesc();

            return [
                'dates' => $dates->all(),
                'valid_count' => $dates->count(),
                'dominant_count' => (int) ($monthCounts->first() ?? 0),
                'dominant_month' => $monthCounts->keys()->first(),
            ];
        })->sort(function (array $left, array $right): int {
            return [$right['dominant_count'], $right['valid_count']]
                <=> [$left['dominant_count'], $left['valid_count']];
        })->values();

        /** @var array{dates: array<string, string>, valid_count: int, dominant_count: int, dominant_month: string|null} $selected */
        $selected = $candidates->first();
        $dominantMonth = null;
        if (
            $selected['valid_count'] >= 5
            && ($selected['dominant_count'] / $selected['valid_count']) >= 0.7
        ) {
            $dominantMonth = $selected['dominant_month'];
        }

        return ['dates' => $selected['dates'], 'dominant_month' => $dominantMonth];
    }

    private function statusFromText(?string $place): string
    {
        $text = mb_strtolower(trim((string) $place));
        if ($text === 'วันอาทิตย์' || str_contains($text, 'วันหยุด')) {
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
