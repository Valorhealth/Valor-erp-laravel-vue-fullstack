<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesCallActivity;
use App\Services\SalesCalls\SalesCallReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SalesCallController extends Controller
{
    public function day(Request $request, SalesCallReportService $reports): JsonResponse
    {
        $this->authorizeSale($request, $reports);
        $data = $request->validate(['date' => ['required', 'date']]);
        $rows = SalesCallActivity::query()
            ->where('seller_user_id', $request->user()->id)
            ->whereDate('activity_date', $data['date'])
            ->orderBy('call_no')
            ->get();

        return response()->json([
            'data' => ['date' => $data['date'], 'rows' => $rows],
            'options' => $reports->options($request->user()),
        ]);
    }

    public function saveDay(Request $request, SalesCallReportService $reports): JsonResponse
    {
        $this->authorizeSale($request, $reports);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'rows' => ['required', 'array', 'max:100'],
            'rows.*.call_id' => ['nullable', 'integer', 'distinct'],
            'rows.*.place_of_work' => ['nullable', 'string', 'max:500'],
            'rows.*.customer_name' => ['nullable', 'string', 'max:500'],
            'rows.*.specialty' => ['nullable', 'string', 'max:255'],
            'rows.*.department' => ['nullable', 'string', 'max:100'],
            'rows.*.customer_group' => ['nullable', 'string', 'max:100'],
            'rows.*.product_1' => ['nullable', 'string', 'max:100'],
            'rows.*.objective' => ['required', Rule::in(SalesCallReportService::OBJECTIVES)],
            'rows.*.call_detail_objective' => ['nullable', 'string', 'max:10000'],
            'rows.*.outcome' => ['required', Rule::in(SalesCallReportService::OUTCOMES)],
            'rows.*.call_detail_outcome' => ['nullable', 'string', 'max:10000'],
            'rows.*.next_follow_up_date' => ['nullable', 'date'],
            'rows.*.notes' => ['nullable', 'string', 'max:10000'],
            'rows.*.sm_comment' => ['nullable', 'string', 'max:10000'],
            'rows.*.status' => ['required', Rule::in(SalesCallReportService::STATUSES)],
        ]);

        $meaningfulRows = collect($data['rows'])->filter(fn (array $row): bool => collect([
            $row['place_of_work'] ?? null,
            $row['customer_name'] ?? null,
            $row['specialty'] ?? null,
            $row['department'] ?? null,
            $row['customer_group'] ?? null,
            $row['product_1'] ?? null,
            $row['call_detail_objective'] ?? null,
            $row['call_detail_outcome'] ?? null,
            $row['next_follow_up_date'] ?? null,
            $row['notes'] ?? null,
            $row['sm_comment'] ?? null,
        ])->contains(fn (mixed $value): bool => trim((string) $value) !== ''))->values();

        $rows = DB::transaction(function () use ($request, $data, $meaningfulRows) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sales-call-day-'.$request->user()->id.'-'.$data['date']]);
            $existing = SalesCallActivity::query()
                ->where('seller_user_id', $request->user()->id)
                ->whereDate('activity_date', $data['date'])
                ->lockForUpdate()
                ->get()
                ->keyBy('call_id');
            $incomingIds = $meaningfulRows->pluck('call_id')->filter()->map(fn (mixed $id): int => (int) $id)->values();

            abort_if($incomingIds->contains(fn (int $id): bool => ! $existing->has($id)), 403, 'พบรายการที่ไม่ได้เป็นของคุณหรือไม่อยู่ในวันที่เลือก');

            if ($existing->isNotEmpty()) {
                SalesCallActivity::query()->whereIn('call_id', $existing->keys())->update(['call_no' => DB::raw('call_no + 1000000')]);
            }
            SalesCallActivity::query()->whereIn('call_id', $existing->keys()->diff($incomingIds))->delete();

            return $meaningfulRows->map(function (array $row, int $index) use ($request, $data, $existing): SalesCallActivity {
                $activity = isset($row['call_id']) ? $existing->get((int) $row['call_id']) : new SalesCallActivity;
                $activity->forceFill([
                    'seller_user_id' => $request->user()->id,
                    'seller_username' => $request->user()->username,
                    'call_no' => $index + 1,
                    'activity_date' => $data['date'],
                    'activity_type' => 'เข้าพบ',
                    'place_of_work' => $this->nullableText($row['place_of_work'] ?? null),
                    'customer_name' => $this->nullableText($row['customer_name'] ?? null),
                    'specialty' => $row['specialty'] ?? null,
                    'department' => $row['department'] ?? null,
                    'customer_group' => $row['customer_group'] ?? null,
                    'product_1' => $row['product_1'] ?? null,
                    'product_interest' => $row['product_1'] ?? null,
                    'objective' => $row['objective'],
                    'call_detail_objective' => $this->nullableText($row['call_detail_objective'] ?? null),
                    'outcome' => $row['outcome'],
                    'call_detail_outcome' => $this->nullableText($row['call_detail_outcome'] ?? null),
                    'opportunity_stage' => $this->stageFor($row['objective'], $row['outcome']),
                    'next_follow_up_date' => $row['next_follow_up_date'] ?? null,
                    'next_action' => $this->nullableText($row['call_detail_outcome'] ?? null),
                    'notes' => $this->nullableText($row['notes'] ?? null),
                    'sm_comment' => $this->nullableText($row['sm_comment'] ?? null),
                    'status' => $row['status'],
                    'estimated_value' => $activity->estimated_value ?? 0,
                    'actual_value' => $activity->actual_value ?? 0,
                ])->save();

                return $activity->fresh();
            });
        });

        return response()->json(['data' => ['date' => $data['date'], 'rows' => $rows], 'message' => 'บันทึก Call Sale ประจำวันเรียบร้อยแล้ว']);
    }

    public function index(Request $request, SalesCallReportService $reports): JsonResponse
    {
        $this->authorizeManager($request, $reports);
        $filters = $this->filters($request);
        $page = $reports->filteredQuery($request->user(), $filters)
            ->orderByDesc('activity_date')->orderBy('call_no')->orderByDesc('call_id')
            ->paginate($filters['per_page'] ?? 25)->withQueryString();

        return response()->json(['data' => $page, 'options' => $reports->options($request->user())]);
    }

    public function history(Request $request, SalesCallReportService $reports): JsonResponse
    {
        $this->authorizeSale($request, $reports);
        $filters = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);
        $page = $reports->filteredQuery($request->user(), $filters)
            ->orderByDesc('activity_date')->orderBy('call_no')->orderByDesc('call_id')
            ->paginate($filters['per_page'] ?? 50)->withQueryString();

        return response()->json(['data' => $page]);
    }

    public function dashboard(Request $request, SalesCallReportService $reports): JsonResponse
    {
        $this->authorizeManager($request, $reports);
        $filters = $this->filters($request);

        return response()->json(['data' => $reports->dashboard($request->user(), $filters), 'options' => $reports->options($request->user())]);
    }

    public function exportExcel(Request $request, SalesCallReportService $reports): mixed
    {
        $this->authorizeManager($request, $reports);
        $filters = $this->filters($request);
        $activities = $reports->filteredQuery($request->user(), $filters)->orderBy('activity_date')->orderBy('call_no')->get();
        abort_if($activities->isEmpty(), 422, 'ไม่พบข้อมูล Call Sale ตามตัวกรอง');

        return response($reports->excel($activities, $reports->dashboard($request->user(), $filters), $request->user()), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Call_Sale_Report_'.now()->format('Ymd_His').'.xlsx"',
        ]);
    }

    public function exportDashboard(Request $request, SalesCallReportService $reports): mixed
    {
        $this->authorizeManager($request, $reports);
        $filters = $this->filters($request);
        $dashboard = $reports->dashboard($request->user(), $filters);
        abort_if($dashboard['summary']['total_activities'] === 0, 422, 'ไม่พบข้อมูล Call Sale ตามตัวกรอง');

        return response($reports->pdf($dashboard, $request->user(), $filters), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Call_Sale_Dashboard_'.now()->format('Ymd_His').'.pdf"',
        ]);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'seller_ids' => ['nullable', 'array'],
            'seller_ids.*' => ['integer', 'exists:users,id'],
            'seller_departments' => ['nullable', 'array'],
            'seller_departments.*' => ['string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'products' => ['nullable', 'array'],
            'products.*' => ['string', 'max:100'],
            'outcomes' => ['nullable', 'array'],
            'outcomes.*' => ['string', Rule::in(SalesCallReportService::OUTCOMES)],
            'stages' => ['nullable', 'array'],
            'stages.*' => ['string', Rule::in(SalesCallReportService::STAGES)],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::in(SalesCallReportService::STATUSES)],
            'customer' => ['nullable', 'string', 'max:500'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
        ]);
    }

    private function authorizeManager(Request $request, SalesCallReportService $reports): void
    {
        abort_unless($reports->canViewTeam($request->user()), 403, 'รายงาน Call Sale สำหรับ Approve และ Super Admin เท่านั้น');
    }

    private function authorizeSale(Request $request, SalesCallReportService $reports): void
    {
        abort_if($reports->canViewTeam($request->user()), 403, 'บัญชี Approve ใช้หน้ารายงาน Call Sale');
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
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
