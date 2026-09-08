<?php

namespace App\Services\SalesCalls;

use App\Models\SalesCallActivity;
use App\Models\User;
use App\Services\Authorization\RoleAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class SalesCallReportService
{
    public const SPECIALTIES = [
        'Internal Medicine', 'Surgery', 'Pediatric', 'Anesthesiologist', 'Radiologist/X-ray',
        'Radio Intervention', 'Family Physician', 'ENT', 'Rehabilitation Medicine', 'Physiotherapist',
        'Orthopedic', 'Cardiology', 'Cardiac', 'Pediatric Cardiology', 'Gastroenterology & Hepatology',
        'Endocrinology & Metabolism', 'Allergy & Clinical Immunology', 'Pain Management',
        'Cardiac Electrophysiology', 'Anti Aging', 'Obstetrics and Gynecology', 'Pharmacist',
        'Nurse', 'Key Influencer', 'เครื่องมือแพทย์', 'พัสดุ / จัดซื้อ', 'ฝ่ายการเงิน',
        'เลขา / ธุรการ', 'Supply', 'Other',
    ];

    public const DEPARTMENTS = ['OPD', 'IPD', 'ER ฉุกเฉิน', 'OR ห้องผ่าตัด', 'MED', 'SUR', 'PED', 'OB-GYN', 'ORTHO', 'ENT', 'PT/REHAP', 'RADIOLOGY', 'PHARMACY', 'LAB', 'ICU/NICU', 'OTHER'];

    public const GROUPS = ['Doctor', 'Nurse', 'Director/Committee/Board', 'Med tech', 'Purchase', 'Finance', 'Secretary/Admin', 'Supply', 'Event', 'Other'];

    public const PRODUCTS = ['AVANOS-DH', 'BRE', 'BOSTON', 'OSTEOCLOUD', 'DARWIN', 'DERAMED', 'ECHOPULSE', 'FIBROSCAN', 'MICROCUFF', 'MODGUT', 'NASALEZE', 'SMILE FEET', 'S-PATCH'];

    public const OBJECTIVES = ['Awareness', 'Trial', 'Sales', 'Usage', 'List', 'Event', 'Others'];

    public const OUTCOMES = ['Success', 'Failure', 'No Call'];

    public const STAGES = ['ลูกค้าเป้าหมาย', 'เริ่มติดต่อ', 'ค้นหาความต้องการ', 'นำเสนอ/เสนอราคา', 'เจรจาต่อรอง', 'ชนะการขาย', 'แพ้การขาย'];

    public const STATUSES = ['ทำงาน', 'ลา', 'วันหยุดบริษัท'];

    /** @param array<string, mixed> $filters */
    public function filteredQuery(User $actor, array $filters): Builder
    {
        $query = SalesCallActivity::query()->with('seller:id,username,first_name,last_name,department');
        $manager = $this->canViewTeam($actor);

        if (! $manager) {
            $query->where('seller_user_id', $actor->id);
        } elseif (($filters['seller_ids'] ?? []) !== []) {
            $query->whereIn('seller_user_id', $filters['seller_ids']);
        }

        if ($manager && ($filters['seller_departments'] ?? []) !== []) {
            $query->whereHas('seller', fn (Builder $sellerQuery) => $sellerQuery
                ->whereIn('department', $filters['seller_departments']));
        }

        return $query
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('activity_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $date) => $q->whereDate('activity_date', '<=', $date))
            ->when($filters['products'] ?? [], fn (Builder $q, array $products) => $q->whereIn('product_1', $products))
            ->when($filters['outcomes'] ?? [], fn (Builder $q, array $outcomes) => $q->whereIn('outcome', $outcomes))
            ->when($filters['stages'] ?? [], fn (Builder $q, array $stages) => $q->whereIn('opportunity_stage', $stages))
            ->when($filters['statuses'] ?? [], fn (Builder $q, array $statuses) => $q->whereIn('status', $statuses))
            ->when($filters['customer'] ?? null, function (Builder $q, string $customer): void {
                $escaped = addcslashes($customer, '%_\\');
                $q->where('customer_name', 'ilike', "%{$escaped}%");
            });
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function dashboard(User $actor, array $filters): array
    {
        $base = $this->filteredQuery($actor, $filters)->without('seller');
        $bySellerDepartment = (clone $base)
            ->leftJoin('users as call_sellers', 'call_sellers.id', '=', 'sales_call_activities.seller_user_id')
            ->selectRaw("COALESCE(NULLIF(BTRIM(call_sellers.department), ''), 'ไม่ระบุแผนก') AS department, COUNT(*) AS total, SUM(CASE WHEN outcome = 'Success' AND status = 'ทำงาน' THEN 1 ELSE 0 END) AS success_calls, SUM(CASE WHEN outcome = 'Failure' AND status = 'ทำงาน' THEN 1 ELSE 0 END) AS failure_calls, SUM(CASE WHEN outcome = 'No Call' AND status = 'ทำงาน' THEN 1 ELSE 0 END) AS no_calls, COUNT(DISTINCT CASE WHEN status = 'ทำงาน' THEN activity_date END) AS working_days, ROUND(CASE WHEN COUNT(*) > 0 THEN SUM(CASE WHEN outcome = 'Success' AND status = 'ทำงาน' THEN 1 ELSE 0 END)::numeric * 100 / COUNT(*) ELSE 0 END, 2) AS success_rate, ROUND(CASE WHEN COUNT(DISTINCT CASE WHEN status = 'ทำงาน' THEN activity_date END) > 0 THEN COUNT(*)::numeric / COUNT(DISTINCT CASE WHEN status = 'ทำงาน' THEN activity_date END) ELSE 0 END, 2) AS calls_per_working_day")
            ->groupByRaw("COALESCE(NULLIF(BTRIM(call_sellers.department), ''), 'ไม่ระบุแผนก')")
            ->orderByDesc('total')
            ->get();
        $total = (clone $base)->count();
        $completed = (clone $base)->where('status', 'ทำงาน')->count();
        $won = (clone $base)->where('opportunity_stage', 'ชนะการขาย')->count();
        $success = (clone $base)->where('outcome', 'Success')->where('status', 'ทำงาน')->count();
        $failure = (clone $base)->where('outcome', 'Failure')->where('status', 'ทำงาน')->count();
        $noCall = (clone $base)->where('outcome', 'No Call')->where('status', 'ทำงาน')->count();
        $workingDays = (int) ((clone $base)->where('status', 'ทำงาน')->distinct('activity_date')->count('activity_date'));
        $today = now()->toDateString();
        $hospitalExpression = "COALESCE(NULLIF(BTRIM(place_of_work), ''), NULLIF(BTRIM(customer_name), ''))";

        return [
            'summary' => [
                'total_activities' => $total,
                'completed_activities' => $completed,
                'active_customers' => (int) ((clone $base)->selectRaw('COUNT(DISTINCT LOWER(BTRIM(customer_name))) AS total')->first()?->total ?? 0),
                'duration_minutes' => (int) (clone $base)->sum('duration_minutes'),
                'pipeline_value' => round((float) (clone $base)->whereNotIn('opportunity_stage', ['ชนะการขาย', 'แพ้การขาย'])->sum('estimated_value'), 2),
                'won_value' => round((float) (clone $base)->where('opportunity_stage', 'ชนะการขาย')->sum('actual_value'), 2),
                'won_activities' => $won,
                'success_calls' => $success,
                'failure_calls' => $failure,
                'no_calls' => $noCall,
                'working_days' => $workingDays,
                'calls_per_working_day' => $workingDays > 0 ? round($total / $workingDays, 2) : 0,
                'conversion_rate' => $total > 0 ? round($success * 100 / $total, 2) : 0,
                'follow_ups_due' => (clone $base)->whereNotNull('next_follow_up_date')->whereDate('next_follow_up_date', '<=', $today)->where('status', 'ทำงาน')->count(),
            ],
            'by_seller' => (clone $base)->selectRaw("seller_user_id, seller_username, COUNT(*) AS total, SUM(CASE WHEN outcome = 'Success' AND status = 'ทำงาน' THEN 1 ELSE 0 END) AS success_calls, SUM(CASE WHEN outcome = 'Failure' AND status = 'ทำงาน' THEN 1 ELSE 0 END) AS failure_calls, SUM(CASE WHEN outcome = 'No Call' AND status = 'ทำงาน' THEN 1 ELSE 0 END) AS no_calls, COUNT(DISTINCT CASE WHEN status = 'ทำงาน' THEN activity_date END) AS working_days, ROUND(CASE WHEN COUNT(*) > 0 THEN SUM(CASE WHEN outcome = 'Success' AND status = 'ทำงาน' THEN 1 ELSE 0 END)::numeric * 100 / COUNT(*) ELSE 0 END, 2) AS success_rate, ROUND(CASE WHEN COUNT(DISTINCT CASE WHEN status = 'ทำงาน' THEN activity_date END) > 0 THEN COUNT(*)::numeric / COUNT(DISTINCT CASE WHEN status = 'ทำงาน' THEN activity_date END) ELSE 0 END, 2) AS calls_per_working_day, COUNT(DISTINCT NULLIF(LOWER(BTRIM(customer_name)), '')) AS active_customers, SUM(CASE WHEN next_follow_up_date IS NOT NULL THEN 1 ELSE 0 END) AS follow_ups, COALESCE(SUM(duration_minutes), 0) AS duration_minutes, COALESCE(SUM(estimated_value), 0) AS pipeline_value, COALESCE(SUM(actual_value), 0) AS actual_value")->groupBy('seller_user_id', 'seller_username')->orderByDesc('total')->get(),
            'by_seller_department' => $bySellerDepartment,
            'performance_matrix' => (clone $base)->selectRaw("seller_username, COALESCE(NULLIF(BTRIM(place_of_work), ''), '-') AS place_of_work, COUNT(*) AS total, SUM(CASE WHEN outcome = 'Success' AND status = 'ทำงาน' THEN 1 ELSE 0 END) AS success_calls, SUM(CASE WHEN outcome = 'Failure' AND status = 'ทำงาน' THEN 1 ELSE 0 END) AS failure_calls, SUM(CASE WHEN outcome = 'No Call' AND status = 'ทำงาน' THEN 1 ELSE 0 END) AS no_calls, COUNT(DISTINCT CASE WHEN status = 'ทำงาน' THEN activity_date END) AS working_days, ROUND(CASE WHEN COUNT(*) > 0 THEN SUM(CASE WHEN outcome = 'Success' AND status = 'ทำงาน' THEN 1 ELSE 0 END)::numeric * 100 / COUNT(*) ELSE 0 END, 2) AS success_rate, ROUND(CASE WHEN COUNT(DISTINCT CASE WHEN status = 'ทำงาน' THEN activity_date END) > 0 THEN COUNT(*)::numeric / COUNT(DISTINCT CASE WHEN status = 'ทำงาน' THEN activity_date END) ELSE 0 END, 2) AS calls_per_working_day")->groupBy('seller_username', 'place_of_work')->orderBy('seller_username')->orderByDesc('total')->get(),
            'by_stage' => (clone $base)->selectRaw('opportunity_stage AS label, COUNT(*) AS total, COALESCE(SUM(estimated_value), 0) AS value')->groupBy('opportunity_stage')->orderByDesc('total')->get(),
            'by_outcome' => (clone $base)->selectRaw('outcome AS label, COUNT(*) AS total')->groupBy('outcome')->orderByDesc('total')->get(),
            'by_activity_type' => (clone $base)->selectRaw("COALESCE(product_1, '-') AS label, COUNT(*) AS total")->groupBy('product_1')->orderByDesc('total')->get(),
            'by_objective' => (clone $base)->selectRaw("COALESCE(objective, '-') AS label, COUNT(*) AS total, SUM(CASE WHEN outcome = 'Success' THEN 1 ELSE 0 END) AS success_calls, SUM(CASE WHEN outcome = 'Failure' THEN 1 ELSE 0 END) AS failure_calls, SUM(CASE WHEN outcome = 'No Call' THEN 1 ELSE 0 END) AS no_calls")->groupBy('objective')->orderByDesc('total')->get(),
            'by_product' => (clone $base)->selectRaw("COALESCE(product_1, '-') AS label, COUNT(*) AS total, SUM(CASE WHEN outcome = 'Success' THEN 1 ELSE 0 END) AS success_calls, COUNT(DISTINCT NULLIF(LOWER(BTRIM(customer_name)), '')) AS customers_called, ROUND(CASE WHEN COUNT(*) > 0 THEN SUM(CASE WHEN outcome = 'Success' THEN 1 ELSE 0 END)::numeric * 100 / COUNT(*) ELSE 0 END, 2) AS success_rate")->groupBy('product_1')->orderByDesc('total')->get(),
            'by_specialty' => (clone $base)->selectRaw("COALESCE(specialty, '-') AS label, COUNT(*) AS total, SUM(CASE WHEN outcome = 'Success' THEN 1 ELSE 0 END) AS success_calls, SUM(CASE WHEN outcome = 'Failure' THEN 1 ELSE 0 END) AS failure_calls, ROUND(CASE WHEN COUNT(*) > 0 THEN SUM(CASE WHEN outcome = 'Success' THEN 1 ELSE 0 END)::numeric * 100 / COUNT(*) ELSE 0 END, 2) AS success_rate")->groupBy('specialty')->orderByDesc('total')->get(),
            'product_customer_matrix' => (clone $base)->selectRaw("COALESCE(product_1, '-') AS product, COALESCE(NULLIF(BTRIM(place_of_work), ''), '-') AS place_of_work, COALESCE(NULLIF(BTRIM(customer_name), ''), '-') AS customer_name, COUNT(*) AS total, SUM(CASE WHEN outcome = 'Success' THEN 1 ELSE 0 END) AS success_calls, SUM(CASE WHEN outcome = 'Failure' THEN 1 ELSE 0 END) AS failure_calls, ROUND(CASE WHEN COUNT(*) > 0 THEN SUM(CASE WHEN outcome = 'Success' THEN 1 ELSE 0 END)::numeric * 100 / COUNT(*) ELSE 0 END, 2) AS success_rate, MAX(activity_date) AS last_call_date")->groupBy('product', 'place_of_work', 'customer_name')->orderByDesc('total')->limit(100)->get(),
            'hospital_purchase_ranking' => (clone $base)
                ->where('status', 'ทำงาน')
                ->where('outcome', 'Success')
                ->whereRaw("{$hospitalExpression} IS NOT NULL")
                ->selectRaw("{$hospitalExpression} AS hospital_name, COUNT(*) AS purchase_calls, COUNT(DISTINCT NULLIF(BTRIM(product_1), '')) AS products_count, COUNT(DISTINCT NULLIF(LOWER(BTRIM(customer_name)), '')) AS contacts_count, COALESCE(SUM(actual_value), 0) AS actual_value, MAX(activity_date) AS last_purchase_date")
                ->groupByRaw($hospitalExpression)
                ->orderByDesc('actual_value')
                ->orderByDesc('purchase_calls')
                ->orderBy('hospital_name')
                ->limit(20)
                ->get(),
            'success_breakdown' => (clone $base)->where('outcome', 'Success')->selectRaw("seller_username, COALESCE(product_1, '-') AS product, COALESCE(specialty, '-') AS specialty, COALESCE(NULLIF(BTRIM(place_of_work), ''), '-') AS place_of_work, COUNT(*) AS total")->groupBy('seller_username', 'product', 'specialty', 'place_of_work')->orderByDesc('total')->get(),
            'failure_breakdown' => (clone $base)->where('outcome', 'Failure')->selectRaw("seller_username, COALESCE(product_1, '-') AS product, COALESCE(specialty, '-') AS specialty, COALESCE(NULLIF(BTRIM(place_of_work), ''), '-') AS place_of_work, COUNT(*) AS total")->groupBy('seller_username', 'product', 'specialty', 'place_of_work')->orderByDesc('total')->get(),
            'daily_trend' => (clone $base)->selectRaw('activity_date, COUNT(*) AS total, COALESCE(SUM(estimated_value), 0) AS pipeline_value')->groupBy('activity_date')->orderBy('activity_date')->get(),
            'top_customers' => (clone $base)->selectRaw('MAX(customer_name) AS customer_name, COUNT(*) AS total, COALESCE(SUM(estimated_value), 0) AS pipeline_value, MAX(activity_date) AS last_activity_date')->groupByRaw('LOWER(BTRIM(customer_name))')->orderByDesc('total')->limit(10)->get(),
            'upcoming_follow_ups' => (clone $base)->with('seller:id,username,first_name,last_name')->whereNotNull('next_follow_up_date')->where('status', 'ทำงาน')->orderBy('next_follow_up_date')->limit(10)->get(),
        ];
    }

    /** @return array<string, mixed> */
    public function options(User $actor): array
    {
        $sellerQuery = User::query()->select(['id', 'username', 'first_name', 'last_name', 'department', 'role'])->orderBy('username');
        $sellers = $sellerQuery->get()->filter(fn (User $user) => RoleAccess::groupForRole($user->role) === RoleAccess::GROUP_SALE);
        $manager = $this->canViewTeam($actor);
        if (! $manager) {
            $sellers = $sellers->where('id', $actor->id);
        }
        $savedOptions = SalesCallActivity::query()
            ->when(! $manager, fn (Builder $query) => $query->where('seller_user_id', $actor->id));

        return [
            'sellers' => $sellers->values(),
            'seller_departments' => $sellers->pluck('department')->filter()->unique()->sort()->values(),
            'specialties' => $this->mergeSavedOptions($savedOptions, 'specialty', self::SPECIALTIES),
            'departments' => $this->mergeSavedOptions($savedOptions, 'department', self::DEPARTMENTS),
            'groups' => $this->mergeSavedOptions($savedOptions, 'customer_group', self::GROUPS),
            'products' => $this->mergeSavedOptions($savedOptions, 'product_1', self::PRODUCTS),
            'objectives' => self::OBJECTIVES,
            'outcomes' => self::OUTCOMES,
            'stages' => self::STAGES,
            'statuses' => self::STATUSES,
        ];
    }

    /** @param Builder<SalesCallActivity> $query @param array<int, string> $defaults @return array<int, string> */
    private function mergeSavedOptions(Builder $query, string $column, array $defaults): array
    {
        $saved = (clone $query)
            ->whereNotNull($column)
            ->whereRaw("BTRIM({$column}) <> ''")
            ->distinct()
            ->orderBy($column)
            ->pluck($column);

        return collect($defaults)
            ->merge($saved)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->values()
            ->all();
    }

    public function canViewTeam(User $user): bool
    {
        return in_array(RoleAccess::groupForRole($user->role), [RoleAccess::GROUP_APPROVE, RoleAccess::GROUP_SUPER_ADMIN], true);
    }

    /** @param Collection<int, SalesCallActivity> $activities @param array<string, mixed> $dashboard */
    public function excel(Collection $activities, array $dashboard, User $actor): string
    {
        $book = new Spreadsheet;
        $summary = $book->getActiveSheet();
        $summary->setTitle('ภาพรวม');
        $summary->setShowGridlines(false);
        $summary->mergeCells('A1:H1')->setCellValue('A1', 'VALOR ERP — CALL SALE REPORT');
        $summary->mergeCells('A2:H2')->setCellValue('A2', 'สร้างเมื่อ '.now()->format('Y-m-d H:i').' โดย '.$actor->username);
        $metrics = $dashboard['summary'];
        $summary->fromArray([
            ['Total Call', $metrics['total_activities'], 'Success Call', $metrics['success_calls'], 'Failure Call', $metrics['failure_calls'], 'No Call', $metrics['no_calls']],
            ['Success Rate', $metrics['conversion_rate'] / 100, 'Working Day', $metrics['working_days'], 'Call / Working Day', $metrics['calls_per_working_day'], 'Customers Called', $metrics['active_customers']],
        ], null, 'A4');
        $summary->getStyle('A1:H1')->applyFromArray(['font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B82032']]]);
        $summary->getStyle('A4:H5')->getFont()->setBold(true);
        $summary->getStyle('B5')->getNumberFormat()->setFormatCode('0.0%');
        $summary->setCellValue('A8', 'ผลการทำงานแยกตาม Sale');
        $summary->fromArray(['Sale', 'Total Call', 'Success', 'Failure', 'No Call', 'Success Rate', 'Working Day', 'Call / Day'], null, 'A9');
        $row = 10;
        foreach ($dashboard['by_seller'] as $record) {
            $summary->fromArray([$record->seller_username, $record->total, $record->success_calls, $record->failure_calls, $record->no_calls, ((float) $record->success_rate) / 100, $record->working_days, $record->calls_per_working_day], null, "A{$row}");
            $summary->setCellValueExplicit("A{$row}", (string) $record->seller_username, DataType::TYPE_STRING);
            $summary->getStyle("F{$row}")->getNumberFormat()->setFormatCode('0.0%');
            $row++;
        }
        $summary->getStyle('A9:H9')->applyFromArray($this->headerStyle());
        $row += 2;
        $summary->setCellValue("A{$row}", 'ผลการทำงานแยกตามแผนกของ Sale');
        $row++;
        $summary->fromArray(['Department', 'Total Call', 'Success', 'Failure', 'No Call', 'Success Rate', 'Working Day', 'Call / Day'], null, "A{$row}");
        $summary->getStyle("A{$row}:H{$row}")->applyFromArray($this->headerStyle());
        $row++;
        foreach ($dashboard['by_seller_department'] ?? [] as $record) {
            $summary->fromArray([$record->department, $record->total, $record->success_calls, $record->failure_calls, $record->no_calls, ((float) $record->success_rate) / 100, $record->working_days, $record->calls_per_working_day], null, "A{$row}");
            $summary->getStyle("F{$row}")->getNumberFormat()->setFormatCode('0.0%');
            $row++;
        }
        $row += 2;
        $summary->setCellValue("A{$row}", 'อันดับโรงพยาบาลที่ซื้อสินค้า (อิง Success Call)');
        $row++;
        $summary->fromArray(['Rank', 'Hospital / Place of Work', 'Success Purchase', 'Products', 'Contacts', 'Actual Value', 'Last Purchase'], null, "A{$row}");
        $summary->getStyle("A{$row}:G{$row}")->applyFromArray($this->headerStyle());
        $row++;
        foreach ($dashboard['hospital_purchase_ranking'] ?? [] as $index => $record) {
            $summary->fromArray([$index + 1, $record->hospital_name, $record->purchase_calls, $record->products_count, $record->contacts_count, $record->actual_value, $record->last_purchase_date], null, "A{$row}");
            $summary->getStyle("F{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            $row++;
        }
        foreach (range('A', 'H') as $column) {
            $summary->getColumnDimension($column)->setWidth(20);
        }

        $raw = $book->createSheet();
        $raw->setTitle('Raw Data');
        $headers = ['Sale', 'SALE DEPARTMENT', 'Call No.', 'Date', 'PLACE OF WORK', 'CUSTOMER NAME', 'SPECIALTY', 'DEPARTMENT', 'GROUP', 'PRODUCT-1', 'CALL OBJECTIVE', 'CALL DETAIL (Obj)', 'CALL OUTCOME', 'CALL DETAIL (Out)', 'F/U DATE', 'NOTE', 'SM Comment', 'Status'];
        $raw->fromArray($headers, null, 'A1');
        $raw->getStyle('A1:R1')->applyFromArray($this->headerStyle());
        $row = 2;
        foreach ($activities as $activity) {
            $values = [$activity->seller_username, $activity->seller?->department, $activity->call_no, $activity->activity_date?->format('Y-m-d'), $activity->place_of_work, $activity->customer_name, $activity->specialty, $activity->department, $activity->customer_group, $activity->product_1, $activity->objective, $activity->call_detail_objective, $activity->outcome, $activity->call_detail_outcome, $activity->next_follow_up_date?->format('Y-m-d'), $activity->notes, $activity->sm_comment, $activity->status];
            $raw->fromArray($values, null, "A{$row}");
            foreach (range('A', 'R') as $column) {
                $value = $raw->getCell("{$column}{$row}")->getValue();
                $raw->setCellValueExplicit("{$column}{$row}", $value === null ? '' : (string) $value, DataType::TYPE_STRING);
            }
            $row++;
        }
        $last = max($row - 1, 2);
        $raw->setAutoFilter("A1:R{$last}");
        $raw->freezePane('A2');
        foreach (range('A', 'R') as $column) {
            $raw->getColumnDimension($column)->setAutoSize(true);
        }
        $book->setActiveSheetIndex(0);

        return $this->xlsxBytes($book);
    }

    /** @param array<string, mixed> $dashboard @param array<string, mixed> $filters */
    public function pdf(array $dashboard, User $actor, array $filters = []): string
    {
        return app(SalesCallDashboardPdf::class)->render($dashboard, $actor, $filters);
    }

    /** @return array<string, mixed> */
    private function headerStyle(): array
    {
        return ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B82032']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]];
    }

    private function xlsxBytes(Spreadsheet $book): string
    {
        $directory = storage_path('app/private/exports');
        File::ensureDirectoryExists($directory);
        $path = tempnam($directory, 'call-report-');
        if ($path === false) {
            throw new RuntimeException('Unable to create Call Sale Report workbook.');
        }
        try {
            (new Xlsx($book))->save($path);
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('Unable to read Call Sale Report workbook.');
            }

            return $bytes;
        } finally {
            $book->disconnectWorksheets();
            File::delete($path);
        }
    }
}
