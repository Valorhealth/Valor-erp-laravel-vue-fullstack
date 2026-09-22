<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesCallActivity;
use App\Models\SalesCallImportBatch;
use App\Models\User;
use App\Services\Authorization\RoleAccess;
use App\Services\SalesCalls\SalesCallImportService;
use App\Services\SalesCalls\SalesCallImportStorage;
use App\Services\SalesCalls\SalesCallReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SalesCallController extends Controller
{
    public function period(Request $request, SalesCallReportService $reports): JsonResponse
    {
        $this->authorizeSale($request, $reports);
        $range = $this->validatedPeriod($request);
        $rows = SalesCallActivity::query()
            ->where('seller_user_id', $request->user()->id)
            ->whereBetween('activity_date', [$range['date_from'], $range['date_to']])
            ->orderBy('activity_date')
            ->orderBy('call_no')
            ->get();

        return response()->json([
            'data' => [...$range, 'rows' => $rows],
            'options' => $reports->options($request->user()),
        ]);
    }

    public function savePeriod(Request $request, SalesCallReportService $reports): JsonResponse
    {
        $this->authorizeSale($request, $reports);
        $range = $this->validatedPeriod($request);
        $existingPeriodRows = SalesCallActivity::query()
            ->where('seller_user_id', $request->user()->id)
            ->whereBetween('activity_date', [$range['date_from'], $range['date_to']])
            ->get();
        $allowed = fn (string $category, string $column): array => collect($reports->allowedOptions($category))
            ->merge($existingPeriodRows->pluck($column))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->values()
            ->all();
        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'rows' => ['required', 'array', 'max:1500'],
            'rows.*.call_id' => ['nullable', 'integer', 'distinct'],
            'rows.*.activity_date' => ['required', 'date'],
            'rows.*.place_of_work' => ['nullable', 'string', 'max:500'],
            'rows.*.customer_name' => ['nullable', 'string', 'max:500'],
            'rows.*.specialty' => ['nullable', 'string', 'max:255', Rule::in($allowed('specialty', 'specialty'))],
            'rows.*.department' => ['nullable', 'string', 'max:100', Rule::in($allowed('department', 'department'))],
            'rows.*.customer_group' => ['nullable', 'string', 'max:100', Rule::in($allowed('group', 'customer_group'))],
            'rows.*.product_1' => ['nullable', 'string', 'max:100', Rule::in($allowed('product', 'product_1'))],
            'rows.*.objective' => ['required', Rule::in($allowed('objective', 'objective'))],
            'rows.*.call_detail_objective' => ['nullable', 'string', 'max:10000'],
            'rows.*.outcome' => ['nullable', Rule::in($allowed('outcome', 'outcome'))],
            'rows.*.call_detail_outcome' => ['nullable', 'string', 'max:10000'],
            'rows.*.next_follow_up_date' => ['nullable', 'date'],
            'rows.*.notes' => ['nullable', 'string', 'max:10000'],
            'rows.*.sm_comment' => ['nullable', 'string', 'max:10000'],
            'rows.*.status' => ['required', Rule::in($allowed('status', 'status'))],
        ]);
        $outOfRange = collect($data['rows'])->contains(fn (array $row): bool => $row['activity_date'] < $range['date_from'] || $row['activity_date'] > $range['date_to']);
        if ($outOfRange) {
            throw ValidationException::withMessages(['rows' => 'พบวันที่อยู่นอกช่วงครึ่งเดือนที่กำลังบันทึก']);
        }
        $meaningfulRows = collect($data['rows'])->filter(fn (array $row): bool => $this->isMeaningfulRow($row))->values();
        $this->ensureOutcomesSelected($meaningfulRows);

        $rows = DB::transaction(function () use ($request, $range, $meaningfulRows) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sales-call-period-'.$request->user()->id.'-'.$range['date_from']]);
            $existing = SalesCallActivity::query()
                ->where('seller_user_id', $request->user()->id)
                ->whereBetween('activity_date', [$range['date_from'], $range['date_to']])
                ->lockForUpdate()
                ->get()
                ->keyBy('call_id');
            $incomingIds = $meaningfulRows->pluck('call_id')->filter()->map(fn (mixed $id): int => (int) $id)->values();
            abort_if($incomingIds->contains(fn (int $id): bool => ! $existing->has($id)), 403, 'พบรายการที่ไม่ได้เป็นของคุณหรืออยู่นอกช่วงที่เลือก');

            if ($existing->isNotEmpty()) {
                SalesCallActivity::query()->whereIn('call_id', $existing->keys())->update(['call_no' => DB::raw('call_no + 1000000')]);
            }
            SalesCallActivity::query()->whereIn('call_id', $existing->keys()->diff($incomingIds))->delete();

            return $meaningfulRows->groupBy('activity_date')->flatMap(function ($dayRows, string $date) use ($request, $existing) {
                return $dayRows->values()->map(function (array $row, int $index) use ($request, $existing, $date): SalesCallActivity {
                    $activity = isset($row['call_id']) ? $existing->get((int) $row['call_id']) : new SalesCallActivity;
                    $activity->forceFill($this->activityValues($request, $row, $date, $index + 1, $activity))->save();

                    return $activity->fresh();
                });
            })->sortBy([['activity_date', 'asc'], ['call_no', 'asc']])->values();
        });

        return response()->json(['data' => [...$range, 'rows' => $rows], 'message' => 'บันทึก Call Sale ช่วงครึ่งเดือนเรียบร้อยแล้ว']);
    }

    public function importPreview(Request $request, SalesCallReportService $reports, SalesCallImportService $imports): JsonResponse
    {
        $this->authorizeSale($request, $reports);
        $file = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls'],
        ])['file'];
        $parsed = $imports->parse($file, $reports);
        $dates = collect($parsed['rows'])->pluck('activity_date')->unique()->values();
        $existingRows = $dates->isEmpty() ? 0 : SalesCallActivity::query()
            ->where('seller_user_id', $request->user()->id)
            ->whereIn('activity_date', $dates)
            ->count();

        return response()->json(['data' => [
            'summary' => [...$parsed['summary'], 'existing_rows_to_replace' => $existingRows],
            'quality' => $parsed['quality'],
            'preview' => $parsed['preview'],
            'warnings' => $parsed['warnings'],
        ]]);
    }

    public function import(Request $request, SalesCallReportService $reports, SalesCallImportService $imports, SalesCallImportStorage $storage): JsonResponse
    {
        $this->authorizeSale($request, $reports);
        $file = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls'],
            'replace_existing_dates' => ['accepted'],
        ])['file'];
        $parsed = $imports->parse($file, $reports);
        if (($parsed['quality']['invalid_required_rows_count'] ?? 0) > 0) {
            throw ValidationException::withMessages([
                'file' => 'ยังนำเข้าไม่ได้: พบ Call ที่กรอกข้อมูลบังคับไม่ครบ กรุณาแก้ทุกแถวตามรายการในหน้าตรวจสอบก่อน',
            ]);
        }

        $dateFrom = $parsed['summary']['date_from'];
        $dateTo = $parsed['summary']['date_to'];
        abort_if(! $dateFrom || ! $dateTo, 422, 'ไม่พบช่วงวันที่ของข้อมูล Call Sale');
        $reportMonth = $parsed['summary']['report_month']
            ?? Carbon::parse($dateFrom)->startOfMonth()->toDateString();
        $storedPath = $storage->store($file, $request->user()->id, $reportMonth);

        try {
            [$result, $batch, $previousFiles] = DB::transaction(function () use ($request, $file, $parsed, $imports, $storage, $storedPath, $reportMonth, $dateFrom, $dateTo): array {
                $result = $imports->replaceUserDates($request->user(), $parsed['rows']);
                $previousBatches = SalesCallImportBatch::query()
                    ->where('seller_user_id', $request->user()->id)
                    ->whereDate('report_month', $reportMonth)
                    ->lockForUpdate()
                    ->get();
                $batch = SalesCallImportBatch::query()->create([
                    'seller_user_id' => $request->user()->id,
                    'seller_username' => $request->user()->username,
                    'report_month' => $reportMonth,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'original_filename' => $file->getClientOriginalName(),
                    'storage_disk' => $storage->diskName(),
                    'storage_path' => $storedPath,
                    'mime_type' => $file->getClientMimeType(),
                    'file_size' => (int) $file->getSize(),
                    'imported_rows' => $result['imported_rows'],
                    'source_activity_rows' => (int) ($parsed['quality']['skipped_without_specialty'] ?? 0),
                ]);
                $previousFiles = $previousBatches->map(fn (SalesCallImportBatch $previous): array => [
                    'disk' => $previous->storage_disk,
                    'path' => $previous->storage_path,
                ])->values();
                if ($previousBatches->isNotEmpty()) {
                    SalesCallImportBatch::query()->whereIn('id', $previousBatches->pluck('id'))->delete();
                }

                return [$result, $batch, $previousFiles];
            });
        } catch (Throwable $exception) {
            $storage->disk()->delete($storedPath);
            throw $exception;
        }

        foreach ($previousFiles as $previousFile) {
            try {
                $storage->disk($previousFile['disk'])->delete($previousFile['path']);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return response()->json([
            'data' => [...$result, 'import_batch_id' => $batch->id],
            'warnings' => $parsed['warnings'],
            'message' => "นำเข้าข้อมูล {$result['imported_rows']} แถว จาก {$result['imported_dates']} วันเรียบร้อยแล้ว (นับเป็น Call {$result['counted_calls']} ครั้ง)",
        ]);
    }

    public function submissions(Request $request, SalesCallReportService $reports): JsonResponse
    {
        $this->authorizeManager($request, $reports);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $from = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $sellers = User::query()->select(['id', 'username', 'first_name', 'last_name', 'department', 'role'])->orderBy('username')->get()
            ->filter(fn (User $user): bool => RoleAccess::groupForRole($user->role) === RoleAccess::GROUP_SALE)
            ->values();
        $sellerIds = $sellers->pluck('id');
        $counts = SalesCallActivity::query()
            ->whereIn('seller_user_id', $sellerIds)
            ->whereBetween('activity_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("seller_user_id, COUNT(*) AS activity_total, SUM(CASE WHEN specialty IS NOT NULL AND BTRIM(specialty) <> '' AND outcome <> 'No Call' THEN 1 ELSE 0 END) AS call_total")
            ->groupBy('seller_user_id')->get()->keyBy('seller_user_id');
        $batches = SalesCallImportBatch::query()
            ->whereIn('seller_user_id', $sellerIds)
            ->whereDate('report_month', $from->toDateString())
            ->orderByDesc('created_at')->get()->groupBy('seller_user_id');

        $rows = $sellers->map(function (User $seller) use ($counts, $batches): array {
            $files = ($batches->get($seller->id) ?? collect())->map(fn (SalesCallImportBatch $batch): array => [
                'id' => $batch->id,
                'original_filename' => $batch->original_filename,
                'file_size' => $batch->file_size,
                'imported_rows' => $batch->imported_rows,
                'source_activity_rows' => $batch->source_activity_rows,
                'data_deleted_at' => $batch->data_deleted_at?->toIso8601String(),
                'created_at' => $batch->created_at?->toIso8601String(),
            ])->values();
            $activityCount = (int) ($counts->get($seller->id)?->activity_total ?? 0);
            $callCount = (int) ($counts->get($seller->id)?->call_total ?? 0);
            $archivedOnly = $activityCount === 0
                && $files->isNotEmpty()
                && $files->every(fn (array $file): bool => $file['data_deleted_at'] !== null);

            return [
                'seller_user_id' => $seller->id,
                'username' => $seller->username,
                'name' => trim($seller->first_name.' '.$seller->last_name) ?: $seller->username,
                'department' => $seller->department,
                'submitted' => $activityCount > 0,
                'archived_only' => $archivedOnly,
                'activity_count' => $activityCount,
                'call_count' => $callCount,
                'files' => $files,
            ];
        });

        return response()->json(['data' => ['month' => $data['month'], 'rows' => $rows]]);
    }

    public function downloadImport(Request $request, SalesCallImportBatch $batch, SalesCallReportService $reports, SalesCallImportStorage $storage): StreamedResponse
    {
        $this->authorizeManager($request, $reports);
        abort_unless($storage->disk($batch->storage_disk)->exists($batch->storage_path), 404, 'ไม่พบไฟล์ Excel ต้นฉบับในพื้นที่จัดเก็บ');
        $request->attributes->set('document_audit', [
            'document_type' => 'sales_call_source_excel',
            'document_id' => (string) $batch->id,
            'original_filename' => $batch->original_filename,
        ]);

        return response()->streamDownload(function () use ($batch, $storage): void {
            $stream = $storage->disk($batch->storage_disk)->readStream($batch->storage_path);
            abort_if($stream === false, 404, 'ไม่สามารถอ่านไฟล์ Excel ต้นฉบับได้');
            fpassthru($stream);
            fclose($stream);
        }, $batch->original_filename, ['Content-Type' => $batch->mime_type ?: 'application/octet-stream']);
    }

    public function deleteMonths(Request $request, SalesCallReportService $reports): JsonResponse
    {
        $this->authorizeManager($request, $reports);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.seller_user_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'items.*.month' => ['required', 'date_format:Y-m'],
        ]);

        $deleted = DB::transaction(function () use ($request, $data): int {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sales-call-manager-month-delete']);
            $total = 0;
            foreach ($data['items'] as $item) {
                $from = Carbon::createFromFormat('Y-m', $item['month'])->startOfMonth();
                $to = $from->copy()->endOfMonth();
                $total += SalesCallActivity::query()->where('seller_user_id', $item['seller_user_id'])
                    ->whereBetween('activity_date', [$from->toDateString(), $to->toDateString()])->delete();
                SalesCallImportBatch::query()->where('seller_user_id', $item['seller_user_id'])
                    ->whereDate('report_month', $from->toDateString())
                    ->update(['data_deleted_at' => now(), 'deleted_by_user_id' => $request->user()->id]);
            }

            return $total;
        });

        return response()->json(['message' => "ลบข้อมูล Call Sale รายเดือน {$deleted} รายการแล้ว โดยยังเก็บไฟล์ Excel ต้นฉบับไว้เป็นหลักฐาน"]);
    }

    public function updateManagedActivity(Request $request, SalesCallActivity $salesCall, SalesCallReportService $reports): JsonResponse
    {
        $this->authorizeManager($request, $reports);
        $allowed = fn (string $category, string $current): array => collect($reports->allowedOptions($category))->push($current)->filter()->unique()->values()->all();
        $data = $request->validate([
            'place_of_work' => ['required', 'string', 'max:500'],
            'customer_name' => ['required', 'string', 'max:500'],
            'specialty' => ['required', 'string', 'max:255', Rule::in($allowed('specialty', $salesCall->specialty))],
            'department' => ['required', 'string', 'max:100', Rule::in($allowed('department', $salesCall->department))],
            'customer_group' => ['required', 'string', 'max:100', Rule::in($allowed('group', $salesCall->customer_group))],
            'product_1' => ['required', 'string', 'max:100', Rule::in($allowed('product', $salesCall->product_1))],
            'objective' => ['required', Rule::in($allowed('objective', $salesCall->objective))],
            'outcome' => ['required', Rule::in($allowed('outcome', $salesCall->outcome))],
            'status' => ['required', Rule::in($allowed('status', $salesCall->status))],
        ]);
        $salesCall->forceFill([
            ...$data,
            'product_interest' => $data['product_1'],
            'opportunity_stage' => $this->stageFor($data['objective'], $data['outcome']),
        ])->save();

        return response()->json(['data' => $salesCall->fresh(), 'message' => 'แก้ไขข้อมูล Call Sale เรียบร้อยแล้ว']);
    }

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
        $date = $request->validate(['date' => ['required', 'date']])['date'];
        $existingDayRows = SalesCallActivity::query()
            ->where('seller_user_id', $request->user()->id)
            ->whereDate('activity_date', $date)
            ->get();
        $allowed = fn (string $category, string $column): array => collect($reports->allowedOptions($category))
            ->merge($existingDayRows->pluck($column))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->values()
            ->all();

        $data = $request->validate([
            'date' => ['required', 'date'],
            'rows' => ['required', 'array', 'max:100'],
            'rows.*.call_id' => ['nullable', 'integer', 'distinct'],
            'rows.*.place_of_work' => ['nullable', 'string', 'max:500'],
            'rows.*.customer_name' => ['nullable', 'string', 'max:500'],
            'rows.*.specialty' => ['nullable', 'string', 'max:255', Rule::in($allowed('specialty', 'specialty'))],
            'rows.*.department' => ['nullable', 'string', 'max:100', Rule::in($allowed('department', 'department'))],
            'rows.*.customer_group' => ['nullable', 'string', 'max:100', Rule::in($allowed('group', 'customer_group'))],
            'rows.*.product_1' => ['nullable', 'string', 'max:100', Rule::in($allowed('product', 'product_1'))],
            'rows.*.objective' => ['required', Rule::in($allowed('objective', 'objective'))],
            'rows.*.call_detail_objective' => ['nullable', 'string', 'max:10000'],
            'rows.*.outcome' => ['nullable', Rule::in($allowed('outcome', 'outcome'))],
            'rows.*.call_detail_outcome' => ['nullable', 'string', 'max:10000'],
            'rows.*.next_follow_up_date' => ['nullable', 'date'],
            'rows.*.notes' => ['nullable', 'string', 'max:10000'],
            'rows.*.sm_comment' => ['nullable', 'string', 'max:10000'],
            'rows.*.status' => ['required', Rule::in($allowed('status', 'status'))],
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
        $this->ensureOutcomesSelected($meaningfulRows);

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
                    'opportunity_stage' => $this->stageFor(
                        $row['objective'],
                        $row['outcome'] ?? ''
                    ),
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
        $page = $reports->filteredQuery($request->user(), $filters, forceOwn: true)
            ->orderByDesc('activity_date')->orderBy('call_no')->orderByDesc('call_id')
            ->paginate($filters['per_page'] ?? 50)->withQueryString();

        return response()->json(['data' => $page]);
    }

    public function deleteHistory(Request $request, SalesCallReportService $reports): JsonResponse
    {
        $this->authorizeSale($request, $reports);
        $data = $request->validate([
            'call_ids' => ['required', 'array', 'min:1', 'max:100'],
            'call_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $deleted = DB::transaction(function () use ($request, $data): int {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sales-call-history-'.$request->user()->id]);
            $rows = SalesCallActivity::query()
                ->where('seller_user_id', $request->user()->id)
                ->whereIn('call_id', $data['call_ids'])
                ->lockForUpdate()
                ->get();
            abort_unless($rows->count() === count($data['call_ids']), 403, 'ลบได้เฉพาะ Call Sale ของบัญชีคุณ');
            $dates = $rows->pluck('activity_date')->map(fn ($date): string => $date->format('Y-m-d'))->unique();
            SalesCallActivity::query()->whereIn('call_id', $rows->pluck('call_id'))->delete();

            foreach ($dates as $date) {
                $remaining = SalesCallActivity::query()
                    ->where('seller_user_id', $request->user()->id)
                    ->whereDate('activity_date', $date)
                    ->orderBy('call_no')
                    ->lockForUpdate()
                    ->get();
                if ($remaining->isNotEmpty()) {
                    SalesCallActivity::query()->whereIn('call_id', $remaining->pluck('call_id'))->update(['call_no' => DB::raw('call_no + 1000000')]);
                    foreach ($remaining as $index => $activity) {
                        $activity->forceFill(['call_no' => $index + 1])->save();
                    }
                }
            }

            return $rows->count();
        });

        return response()->json(['message' => "ลบประวัติ Call Sale {$deleted} รายการเรียบร้อยแล้ว"]);
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
            'outcomes.*' => ['string', 'max:100'],
            'stages' => ['nullable', 'array'],
            'stages.*' => ['string', Rule::in(SalesCallReportService::STAGES)],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', 'max:100'],
            'customer' => ['nullable', 'string', 'max:500'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
        ]);
    }

    private function authorizeManager(Request $request, SalesCallReportService $reports): void
    {
        abort_unless($reports->canViewTeam($request->user()), 403, 'บัญชีนี้ไม่มีสิทธิ์ดู Call Sale Report');
    }

    private function authorizeSale(Request $request, SalesCallReportService $reports): void
    {
        abort_unless(RoleAccess::canUser($request->user(), 'sales_call_entry'), 403, 'บัญชีนี้ไม่มีสิทธิ์บันทึก Call Sale');
    }

    /** @return array{date_from: string, date_to: string} */
    private function validatedPeriod(Request $request): array
    {
        $range = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);
        if (Carbon::parse($range['date_from'])->diffInDays(Carbon::parse($range['date_to'])) > 15) {
            throw ValidationException::withMessages(['date_to' => 'หนึ่งหน้าสามารถแสดงและบันทึกได้สูงสุด 16 วัน']);
        }

        return $range;
    }

    /** @param array<string, mixed> $row */
    private function isMeaningfulRow(array $row): bool
    {
        return collect([
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
        ])->contains(fn (mixed $value): bool => trim((string) $value) !== '');
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function ensureOutcomesSelected(Collection $rows): void
    {
        if ($rows->contains(fn (array $row): bool => trim((string) ($row['specialty'] ?? '')) !== ''
            && trim((string) ($row['outcome'] ?? '')) === '')) {
            throw ValidationException::withMessages([
                'rows' => 'กรุณาเลือก CALL OUTCOME ให้ครบทุกรายการที่กรอก ระบบจะไม่กำหนดเป็น No Call ให้อัตโนมัติ',
            ]);
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function activityValues(Request $request, array $row, string $date, int $callNo, SalesCallActivity $activity): array
    {
        return [
            'seller_user_id' => $request->user()->id,
            'seller_username' => $request->user()->username,
            'call_no' => $callNo,
            'activity_date' => $date,
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
        ];
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
