<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesCallLookupOption;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

final class SalesCallLookupOptionController extends Controller
{
    public function index(): JsonResponse
    {
        $this->ensureTableExists();

        return response()->json(['data' => $this->allOptions()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureTableExists();
        $data = $request->validate([
            'category' => ['required', Rule::in(SalesCallLookupOption::CATEGORIES)],
            'label' => ['required', 'string', 'max:255'],
        ]);
        $label = $this->cleanLabel($data['label']);
        abort_if($label === '', 422, 'กรุณากรอกชื่อตัวเลือก');

        $option = DB::transaction(function () use ($request, $data, $label): SalesCallLookupOption {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sales-call-option-'.$data['category']]);
            $duplicate = SalesCallLookupOption::query()
                ->where('category', $data['category'])
                ->whereRaw('LOWER(BTRIM(label)) = LOWER(?)', [$label])
                ->first();

            if ($duplicate) {
                abort_if($duplicate->is_active, 422, 'ตัวเลือกนี้มีอยู่แล้ว');
                $duplicate->update(['label' => $label, 'is_active' => true]);

                return $duplicate->fresh();
            }

            $sortOrder = (int) SalesCallLookupOption::query()
                ->where('category', $data['category'])
                ->max('sort_order');

            return SalesCallLookupOption::query()->create([
                'category' => $data['category'],
                'label' => $label,
                'is_active' => true,
                'sort_order' => $sortOrder + 10,
                'created_by_user_id' => $request->user()->id,
            ]);
        });

        return response()->json([
            'data' => $option,
            'message' => 'เพิ่มตัวเลือกส่วนกลางเรียบร้อยแล้ว',
        ], 201);
    }

    public function update(Request $request, SalesCallLookupOption $option): JsonResponse
    {
        $this->ensureTableExists();
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,100000'],
        ]);
        $label = $this->cleanLabel($data['label']);
        abort_if($label === '', 422, 'กรุณากรอกชื่อตัวเลือก');

        DB::transaction(function () use ($option, $data, $label): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sales-call-option-'.$option->category]);
            $locked = SalesCallLookupOption::query()->lockForUpdate()->findOrFail($option->id);
            $duplicate = SalesCallLookupOption::query()
                ->where('category', $locked->category)
                ->whereKeyNot($locked->id)
                ->whereRaw('LOWER(BTRIM(label)) = LOWER(?)', [$label])
                ->exists();
            abort_if($duplicate, 422, 'ตัวเลือกนี้มีอยู่แล้วในหมวดเดียวกัน');

            if ($locked->is_active && ! $data['is_active']) {
                $activeIds = SalesCallLookupOption::query()
                    ->where('category', $locked->category)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->pluck('id');
                abort_if($activeIds->count() <= 1, 422, 'แต่ละหมวดต้องเหลือตัวเลือกที่เปิดใช้งานอย่างน้อย 1 รายการ');
            }

            $locked->update([
                'label' => $label,
                'is_active' => $data['is_active'],
                'sort_order' => $data['sort_order'],
            ]);
        });

        return response()->json([
            'data' => $option->fresh(),
            'message' => 'บันทึกตัวเลือกเรียบร้อยแล้ว',
        ]);
    }

    /** @return Collection<int, SalesCallLookupOption> */
    private function allOptions(): Collection
    {
        return SalesCallLookupOption::query()
            ->with('creator:id,username,first_name,last_name')
            ->orderByRaw("CASE category WHEN 'specialty' THEN 1 WHEN 'department' THEN 2 WHEN 'group' THEN 3 WHEN 'product' THEN 4 WHEN 'objective' THEN 5 WHEN 'outcome' THEN 6 WHEN 'status' THEN 7 ELSE 99 END")
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();
    }

    private function cleanLabel(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function ensureTableExists(): void
    {
        abort_unless(
            Schema::hasTable('sales_call_lookup_options'),
            503,
            'ยังไม่ได้ติดตั้งตาราง Dropdown ส่วนกลาง กรุณารันไฟล์ database/schema/sales_call_lookup_options.sql บน PostgreSQL',
        );
    }
}
