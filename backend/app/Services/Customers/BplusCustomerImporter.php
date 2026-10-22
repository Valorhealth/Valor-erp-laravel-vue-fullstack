<?php

namespace App\Services\Customers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use RuntimeException;

final class BplusCustomerImporter
{
    private const REQUIRED_HEADERS = [
        'AR_CODE', 'AR_NAME', 'ARS_CRE_LIM', 'ARCD_TERM', 'ADDB_COMPANY', 'ADDB_BRANCH', 'ADDB_TAX_ID',
        'ADDB_ADDB_1', 'ADDB_ADDB_2', 'ADDB_ADDB_3', 'ADDB_SUB_DISTRICT',
        'ADDB_DISTRICT', 'ADDB_PROVINCE', 'ADDB_POST', 'ADDB_PHONE',
    ];

    /** @return array<string, int> */
    public function import(string $path, bool $dryRun = false): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("ไม่พบไฟล์ {$path}");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $worksheetInfo = collect($reader->listWorksheetInfo($path));
        $info = $worksheetInfo->firstWhere('worksheetName', 'BplusData') ?? $worksheetInfo->first();
        if (! $info) {
            throw new RuntimeException('ไม่พบ Worksheet ในไฟล์ Bplus');
        }
        $sheetName = (string) $info['worksheetName'];
        $highestColumn = (string) $info['lastColumnLetter'];
        $highestRow = (int) $info['totalRows'];
        $filter = new class implements IReadFilter
        {
            private int $start = 1;

            private int $end = 1;

            public function rows(int $start, int $end): void
            {
                $this->start = $start;
                $this->end = $end;
            }

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row >= $this->start && $row <= $this->end;
            }
        };
        $reader->setReadFilter($filter);
        $filter->rows(1, 1);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet();
        $headerValues = $sheet->rangeToArray("A1:{$highestColumn}1", null, true, false)[0] ?? [];
        $headers = [];
        foreach ($headerValues as $index => $value) {
            $headers[trim((string) $value)] = $index;
        }
        $missing = array_values(array_diff(self::REQUIRED_HEADERS, array_keys($headers)));
        if ($missing !== []) {
            throw new RuntimeException('ไฟล์ Bplus ขาดคอลัมน์: '.implode(', ', $missing));
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet);

        $sourceRows = max($highestRow - 1, 0);
        $customers = [];
        $duplicates = 0;
        $skipped = 0;
        for ($start = 2; $start <= $highestRow; $start += 1000) {
            $end = min($start + 999, $highestRow);
            $filter->rows($start, $end);
            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet();
            foreach ($sheet->rangeToArray("A{$start}:{$highestColumn}{$end}", null, true, false) as $values) {
                $get = fn (string $header): string => $this->clean($values[$headers[$header]] ?? null);
                $code = $get('AR_CODE');
                $name = $get('AR_NAME');
                if ($code === '' || $name === '') {
                    $skipped++;

                    continue;
                }

                if (isset($customers[$code])) {
                    $duplicates++;
                }
                $province = $get('ADDB_PROVINCE');
                $district = $get('ADDB_DISTRICT');
                $subDistrict = $get('ADDB_SUB_DISTRICT');
                $zipcode = $get('ADDB_POST');
                $fullAddress = $this->fullAddress([
                    $get('ADDB_ADDB_1'), $get('ADDB_ADDB_2'), $get('ADDB_ADDB_3'),
                ], $subDistrict, $district, $province, $zipcode);
                $customers[$code] = [
                    'ar_code' => $code,
                    'ar_name' => $name,
                    'credit_limit' => $this->nonNegativeDecimal($values[$headers['ARS_CRE_LIM']] ?? null),
                    'credit_term' => $this->nonNegativeInteger($values[$headers['ARCD_TERM']] ?? null),
                    'tax_id' => $this->nullable($get('ADDB_TAX_ID')),
                    'billing_address' => $this->nullable($fullAddress),
                    'delivery_address' => $this->nullable($fullAddress),
                    'province' => $this->nullable($province),
                    'district' => $this->nullable($district),
                    'sub_district' => $this->nullable($subDistrict),
                    'zipcode' => $this->nullable($zipcode),
                    'address_name' => $this->addressName($get('ADDB_COMPANY'), $get('ADDB_BRANCH')),
                    'tax_branch_code' => $this->taxBranchCode($get('ADDB_BRANCH')),
                    'contact_phone' => $this->nullable($get('ADDB_PHONE')),
                ];
            }
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $sheet);
        }

        $summary = [
            'source_rows' => $sourceRows,
            'unique_customers' => count($customers),
            'duplicate_rows' => $duplicates,
            'skipped_rows' => $skipped,
            'customers_written' => 0,
            'addresses_written' => 0,
            'credit_profiles_written' => 0,
        ];
        if ($dryRun) {
            return $summary;
        }

        DB::transaction(function () use ($customers, $path, &$summary): void {
            $now = now();
            foreach (array_chunk(array_values($customers), 500) as $chunk) {
                $customerRows = array_map(fn (array $item): array => [
                    'ar_code' => $item['ar_code'],
                    'ar_name' => $item['ar_name'],
                    'tax_id' => $item['tax_id'],
                    'billing_address' => $item['billing_address'],
                    'delivery_address' => $item['delivery_address'],
                    'province' => $item['province'],
                    'district' => $item['district'],
                    'sub_district' => $item['sub_district'],
                    'zipcode' => $item['zipcode'],
                    'created_at' => $now,
                ], $chunk);
                DB::table('customers')->upsert(
                    $customerRows,
                    ['ar_code'],
                    ['ar_name', 'tax_id', 'billing_address', 'delivery_address', 'province', 'district', 'sub_district', 'zipcode'],
                );

                $creditRows = array_map(fn (array $item): array => [
                    'ar_code' => $item['ar_code'],
                    'ar_name' => $item['ar_name'],
                    'credit_limit' => $item['credit_limit'],
                    'credit_term' => $item['credit_term'],
                    'credit_outstanding' => 0,
                    'source_file' => basename($path),
                    'updated_at' => $now,
                ], $chunk);
                DB::table('customer_credit_profiles')->upsert(
                    $creditRows,
                    ['ar_code'],
                    ['ar_name', 'credit_limit', 'credit_term', 'source_file', 'updated_at'],
                );

                $ids = DB::table('customers')
                    ->whereIn('ar_code', array_column($chunk, 'ar_code'))
                    ->pluck('id', 'ar_code');
                $addressRows = [];
                foreach ($chunk as $item) {
                    if ($item['billing_address'] === null || ! isset($ids[$item['ar_code']])) {
                        continue;
                    }
                    $addressRows[] = [
                        'customer_id' => $ids[$item['ar_code']],
                        'bplus_address_code' => 'PRIMARY',
                        'address_name' => $item['address_name'],
                        'address_type' => 'both',
                        'full_address' => $item['billing_address'],
                        'province' => $item['province'],
                        'district' => $item['district'],
                        'sub_district' => $item['sub_district'],
                        'zipcode' => $item['zipcode'],
                        'tax_id' => $item['tax_id'],
                        'tax_branch_code' => $item['tax_branch_code'],
                        'contact_name' => null,
                        'contact_phone' => $item['contact_phone'],
                        'delivery_note' => null,
                        'is_default_billing' => true,
                        'is_default_delivery' => true,
                        'is_active' => true,
                        'bplus_synced_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($addressRows !== []) {
                    DB::table('customer_addresses')->upsert(
                        $addressRows,
                        ['customer_id', 'bplus_address_code'],
                        ['address_name', 'address_type', 'full_address', 'province', 'district', 'sub_district', 'zipcode', 'tax_id', 'tax_branch_code', 'contact_phone', 'is_default_billing', 'is_default_delivery', 'is_active', 'bplus_synced_at', 'updated_at'],
                    );
                }
                $summary['customers_written'] += count($customerRows);
                $summary['addresses_written'] += count($addressRows);
                $summary['credit_profiles_written'] += count($creditRows);
            }
        }, 3);

        Cache::forget('reference-data:v3');
        Cache::forget('reference-data:v4');
        Cache::forget('reference-data:v5');

        return $summary;
    }

    private function clean(mixed $value): string
    {
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', trim((string) $value)) ?? '';

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    /** @param list<string> $lines */
    private function fullAddress(array $lines, string $subDistrict, string $district, string $province, string $zipcode): string
    {
        $parts = array_values(array_filter($lines, fn (string $value): bool => $value !== '' && $value !== '-'));
        $bangkok = in_array($province, ['กรุงเทพมหานคร', 'กรุงเทพฯ', 'กรุงเทพ'], true);
        if ($subDistrict !== '' && $subDistrict !== '-') {
            $parts[] = ($bangkok ? 'แขวง' : 'ตำบล').preg_replace('/^(แขวง|ตำบล)\s*/u', '', $subDistrict);
        }
        if ($district !== '' && $district !== '-') {
            $parts[] = ($bangkok ? 'เขต' : 'อำเภอ').preg_replace('/^(เขต|อำเภอ)\s*/u', '', $district);
        }
        if ($province !== '' && $province !== '-') {
            $parts[] = $bangkok ? 'กรุงเทพมหานคร' : 'จังหวัด'.preg_replace('/^จังหวัด\s*/u', '', $province);
        }
        if ($zipcode !== '' && $zipcode !== '-') {
            $parts[] = $zipcode;
        }

        return implode(' ', array_values(array_unique($parts)));
    }

    private function addressName(string $company, string $branch): string
    {
        $company = $company !== '' && $company !== '-' ? $company : 'ที่อยู่หลักจาก Bplus';
        $branch = $branch !== '' && $branch !== '-' ? $branch : '';

        return mb_substr(trim($company.' '.$branch), 0, 500);
    }

    private function taxBranchCode(string $branch): ?string
    {
        if ($branch === '' || $branch === '-') {
            return null;
        }
        if (str_contains($branch, 'สำนักงานใหญ่')) {
            return '00000';
        }
        if (preg_match('/\d{5}/', $branch, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    private function nullable(string $value): ?string
    {
        return $value === '' || $value === '-' ? null : $value;
    }

    private function nonNegativeDecimal(mixed $value): float
    {
        if (is_string($value)) {
            $value = str_replace([',', ' '], '', trim($value));
        }

        return max(is_numeric($value) ? (float) $value : 0, 0);
    }

    private function nonNegativeInteger(mixed $value): int
    {
        return (int) floor($this->nonNegativeDecimal($value));
    }
}
