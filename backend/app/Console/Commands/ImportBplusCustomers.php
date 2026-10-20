<?php

namespace App\Console\Commands;

use App\Services\Customers\BplusCustomerImporter;
use Illuminate\Console\Command;
use Throwable;

final class ImportBplusCustomers extends Command
{
    protected $signature = 'bplus:import-customers {file : Absolute path to the Bplus XLSX file} {--dry-run : Validate and summarize without writing}';

    protected $description = 'นำเข้าหรืออัปเดตรายชื่อลูกหนี้และที่อยู่จากไฟล์ Bplus';

    public function handle(BplusCustomerImporter $importer): int
    {
        try {
            $summary = $importer->import((string) $this->argument('file'), (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['รายการ', 'จำนวน'], [
            ['แถวข้อมูลต้นฉบับ', $summary['source_rows']],
            ['รหัสลูกหนี้ไม่ซ้ำ', $summary['unique_customers']],
            ['แถวรหัสซ้ำที่รวมแล้ว', $summary['duplicate_rows']],
            ['แถวที่ข้าม', $summary['skipped_rows']],
            ['ลูกค้าที่เขียน', $summary['customers_written']],
            ['ที่อยู่ที่เขียน', $summary['addresses_written']],
        ]);
        $this->info($this->option('dry-run') ? 'ตรวจสอบไฟล์สำเร็จ ยังไม่ได้เขียนฐานข้อมูล' : 'นำเข้าข้อมูลลูกหนี้สำเร็จ');

        return self::SUCCESS;
    }
}
