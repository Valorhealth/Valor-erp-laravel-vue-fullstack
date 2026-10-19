<?php

namespace App\Services\Dashboard;

use App\Models\BillingRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class DashboardService
{
    public const SUCCESS_STATUSES = ['รอแอดมินส่งออก', 'อนุมัติแล้ว', 'ดำเนินการเสร็จสิ้น'];

    private const WAITING_MANAGER_STATUSES = ['รออนุมัติ', 'รอหัวหน้าอนุมัติ'];

    private const WAITING_EXPORT_STATUSES = ['รอแอดมินส่งออก'];

    private const NEEDS_REVISION_STATUSES = ['รอแก้ไข', 'ไม่อนุมัติ'];

    private const READY_EXPORT_STATUSES = ['รอแอดมินส่งออก', 'อนุมัติแล้ว'];

    private const COMPLETED_STATUSES = ['ดำเนินการเสร็จสิ้น'];

    /** @param array<int, string> $sales @param array<int, string> $statuses @param array<int, string> $departments @return array<string, mixed> */
    public function summary(
        array $sales = [],
        array $statuses = [],
        array $departments = [],
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $departmentUserIds = $departments === []
            ? []
            : User::query()->whereIn('department', $departments)->pluck('id')->all();
        $filtered = BillingRecord::query()
            ->when($sales !== [], fn (Builder $query) => $query->whereIn('created_by', $sales))
            ->when($statuses !== [], fn (Builder $query) => $query->whereIn('status', $statuses))
            ->when($departments !== [], fn (Builder $query) => $query->whereIn('creator_user_id', $departmentUserIds))
            ->when($dateFrom, fn (Builder $query) => $query->where('bill_date', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $query) => $query->where('bill_date', '<=', $dateTo));

        // Canceled documents remain in the legal/audit count, but never contribute to financial totals.
        $financial = (clone $filtered)->where('status', '!=', 'ยกเลิกบิล');
        $successPlaceholders = implode(', ', array_fill(0, count(self::SUCCESS_STATUSES), '?'));
        $billMetrics = (clone $filtered)->toBase()->selectRaw(
            "COUNT(*) AS total_bills,
             COALESCE(SUM(CASE WHEN status <> 'ยกเลิกบิล' THEN total_amount ELSE 0 END), 0) AS total_revenue,
             COALESCE(SUM(CASE WHEN status IN ({$successPlaceholders}) THEN total_amount ELSE 0 END), 0) AS recognized_revenue,
             SUM(CASE WHEN status IN ({$successPlaceholders}) THEN 1 ELSE 0 END) AS recognized_bills,
             COUNT(DISTINCT CASE WHEN status <> 'ยกเลิกบิล' THEN COALESCE(NULLIF(BTRIM(bplus_customer_code), ''), LOWER(BTRIM(buyer_name))) END) AS active_customers,
             COALESCE(SUM(CASE WHEN status <> 'ยกเลิกบิล' THEN admin_discount_final ELSE 0 END), 0) AS admin_bill_discount",
            [...self::SUCCESS_STATUSES, ...self::SUCCESS_STATUSES],
        )->first();
        $statusBreakdown = (clone $filtered)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->orderByDesc('total')->get();
        $statusCounts = $statusBreakdown->mapWithKeys(fn ($row) => [(string) $row->status => (int) $row->total]);
        $countStatuses = fn (array $wanted): int => array_sum(array_map(
            fn (string $status): int => (int) $statusCounts->get($status, 0),
            $wanted,
        ));
        $waitingManager = $countStatuses(self::WAITING_MANAGER_STATUSES);
        $waitingExport = $countStatuses(self::WAITING_EXPORT_STATUSES);
        $needsRevision = $countStatuses(self::NEEDS_REVISION_STATUSES);
        $readyExport = $countStatuses(self::READY_EXPORT_STATUSES);
        $completedBills = $countStatuses(self::COMPLETED_STATUSES);
        $salesByPerson = (clone $financial)
            ->selectRaw('created_by, COALESCE(SUM(total_amount), 0) AS total')->groupBy('created_by')->orderByDesc('total')->get();
        $salesByDepartment = (clone $financial)
            ->leftJoin('users as dashboard_users', 'dashboard_users.id', '=', 'billing_records.creator_user_id')
            ->selectRaw("COALESCE(NULLIF(BTRIM(dashboard_users.department), ''), 'ไม่ระบุแผนก') AS department, COUNT(*) AS bills, COALESCE(SUM(total_amount), 0) AS total")
            ->groupByRaw("COALESCE(NULLIF(BTRIM(dashboard_users.department), ''), 'ไม่ระบุแผนก')")
            ->orderByDesc('total')
            ->get();
        $dailySales = (clone $financial)
            ->selectRaw('bill_date, COALESCE(SUM(total_amount), 0) AS total')->groupBy('bill_date')->orderBy('bill_date')->get();
        $topCustomers = (clone $financial)
            ->selectRaw("COALESCE(NULLIF(BTRIM(bplus_customer_code), ''), LOWER(BTRIM(buyer_name))) AS customer_key, MAX(buyer_name) AS buyer_name, COUNT(*) AS bills, COALESCE(SUM(total_amount), 0) AS total")
            ->groupByRaw("COALESCE(NULLIF(BTRIM(bplus_customer_code), ''), LOWER(BTRIM(buyer_name)))")
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $itemTotals = DB::table('bill_items')
            ->select('bill_id')
            ->selectRaw('COALESCE(SUM(line_total), 0) AS bill_line_total')
            ->groupBy('bill_id');
        $filteredItems = DB::table('bill_items as items')
            ->join('billing_records as bills', 'bills.bill_id', '=', 'items.bill_id')
            ->leftJoinSub($itemTotals, 'item_totals', 'item_totals.bill_id', '=', 'bills.bill_id')
            ->when($sales !== [], fn ($query) => $query->whereIn('bills.created_by', $sales))
            ->when($statuses !== [], fn ($query) => $query->whereIn('bills.status', $statuses))
            ->when($departments !== [], fn ($query) => $query->whereIn('bills.creator_user_id', $departmentUserIds))
            ->when($dateFrom, fn ($query) => $query->where('bills.bill_date', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->where('bills.bill_date', '<=', $dateTo));
        $filteredItems->where('bills.status', '!=', 'ยกเลิกบิล');
        $grossExpression = 'COALESCE(items.line_total_before_discount, items.quantity * items.unit_price, 0)';
        $lineNetExpression = 'COALESCE(items.line_total, items.quantity * items.unit_price, 0)';
        $allocatedBillDiscount = "CASE WHEN COALESCE(item_totals.bill_line_total, 0) > 0 THEN COALESCE(bills.sale_discount_total, 0) * {$lineNetExpression} / item_totals.bill_line_total ELSE 0 END";
        $itemMetrics = (clone $filteredItems)
            ->selectRaw("COALESCE(SUM(items.quantity), 0) AS quantity, COALESCE(SUM({$grossExpression}), 0) AS gross_amount, COALESCE(SUM({$lineNetExpression} - {$allocatedBillDiscount}), 0) AS net_amount, COALESCE(SUM(items.admin_disocount_unit), 0) AS admin_item_discount")
            ->first();
        $topProducts = (clone $filteredItems)
            ->selectRaw("items.main_category AS sku, MAX(items.sub_category) AS product_name, COALESCE(SUM(items.quantity), 0) AS quantity, COALESCE(SUM({$grossExpression}), 0) AS gross_amount, COALESCE(SUM({$grossExpression} - ({$lineNetExpression} - {$allocatedBillDiscount})), 0) AS discount_amount, COALESCE(SUM({$lineNetExpression} - {$allocatedBillDiscount}), 0) AS net_amount")
            ->groupBy('items.main_category')
            ->orderByDesc('net_amount')
            ->limit(10)
            ->get();
        $grossSales = round((float) ($itemMetrics?->gross_amount ?? 0), 2);
        $totalRevenue = (float) ($billMetrics?->total_revenue ?? 0);
        $adminBillDiscount = (float) ($billMetrics?->admin_bill_discount ?? 0);
        $adminItemDiscount = (float) ($itemMetrics?->admin_item_discount ?? 0);

        return [
            'summary' => [
                'total_revenue' => round((float) ($billMetrics?->total_revenue ?? 0), 2),
                'recognized_revenue' => round((float) ($billMetrics?->recognized_revenue ?? 0), 2),
                'gross_sales' => $grossSales,
                'total_discount' => round(max($grossSales - (float) $totalRevenue, 0), 2),
                'bplus_admin_discount' => round($adminBillDiscount + $adminItemDiscount, 2),
                'total_bills' => (int) ($billMetrics?->total_bills ?? 0),
                'recognized_bills' => (int) ($billMetrics?->recognized_bills ?? 0),
                'completed_bills' => $completedBills,
                'pending_approval' => $waitingManager,
                'waiting_manager' => $waitingManager,
                'waiting_export' => $waitingExport,
                'needs_revision' => $needsRevision,
                'ready_export' => $readyExport,
                'items_sold' => (int) ($itemMetrics?->quantity ?? 0),
                'active_customers' => (int) ($billMetrics?->active_customers ?? 0),
            ],
            'filters' => [
                'sales' => BillingRecord::query()->whereNotNull('created_by')->distinct()->orderBy('created_by')->pluck('created_by'),
                'statuses' => BillingRecord::query()->whereNotNull('status')->distinct()->orderBy('status')->pluck('status'),
                'departments' => User::query()->whereNotNull('department')->where('department', '!=', '')->distinct()->orderBy('department')->pluck('department'),
            ],
            'status_breakdown' => $statusBreakdown,
            'sales_by_person' => $salesByPerson,
            'sales_by_department' => $salesByDepartment,
            'daily_sales' => $dailySales,
            'top_customers' => $topCustomers,
            'top_products' => $topProducts,
            'recent_bills' => (clone $filtered)->select(['bill_id', 'bill_date', 'buyer_name', 'total_amount', 'status', 'created_by', 'created_at'])->orderByDesc('bill_id')->limit(8)->get(),
        ];
    }
}
