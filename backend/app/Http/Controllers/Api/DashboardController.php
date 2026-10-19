<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingRecord;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Documents\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): JsonResponse
    {
        $data = $request->validate([
            'sales' => ['nullable', 'array'],
            'sales.*' => ['string', 'max:255'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', 'max:255'],
            'departments' => ['nullable', 'array'],
            'departments.*' => ['string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        return response()->json(['data' => $dashboard->summary(
            $data['sales'] ?? [],
            $data['statuses'] ?? [],
            $data['departments'] ?? [],
            $data['date_from'] ?? null,
            $data['date_to'] ?? null,
        )]);
    }

    public function report(Request $request, DocumentService $documents): mixed
    {
        $data = $request->validate([
            'sales' => ['nullable', 'array'],
            'sales.*' => ['string', 'max:255'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', 'max:255'],
            'departments' => ['nullable', 'array'],
            'departments.*' => ['string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'scope' => ['nullable', Rule::in(['all', 'successful'])],
        ]);

        $sales = $data['sales'] ?? [];
        $statuses = $data['statuses'] ?? [];
        $departments = $data['departments'] ?? [];
        $departmentUserIds = $departments === []
            ? []
            : User::query()->whereIn('department', $departments)->pluck('id')->all();
        $bills = BillingRecord::query()
            ->with('items')
            ->when($sales !== [], fn ($query) => $query->whereIn('created_by', $sales))
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->when($departments !== [], fn ($query) => $query->whereIn('creator_user_id', $departmentUserIds))
            ->when(($data['scope'] ?? 'all') === 'successful', fn ($query) => $query->whereIn('status', DashboardService::SUCCESS_STATUSES))
            ->when($data['date_from'] ?? null, fn ($query, $date) => $query->where('bill_date', '>=', $date))
            ->when($data['date_to'] ?? null, fn ($query, $date) => $query->where('bill_date', '<=', $date))
            ->orderBy('bill_date')
            ->orderBy('bill_id')
            ->get();

        abort_if($bills->isEmpty(), 422, 'ไม่พบข้อมูลสำหรับสร้าง Report ตามตัวกรอง');

        $filename = sprintf(
            'Sales_Report_%s_to_%s.xlsx',
            $data['date_from'] ?? 'ALL',
            $data['date_to'] ?? now()->format('Y-m-d'),
        );

        return response($documents->salesReportWorkbook($bills, [
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
            'generated_by' => $request->user()->username,
        ]), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
