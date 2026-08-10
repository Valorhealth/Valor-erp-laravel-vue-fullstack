<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesTask;
use App\Models\User;
use App\Services\Authorization\RoleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $group = RoleAccess::groupForRole($request->user()->role);
        $tasks = SalesTask::query()
            ->when(in_array($group, [RoleAccess::GROUP_SALE, RoleAccess::GROUP_DEFAULT], true), fn ($query) => $query->where('assigned_to', $request->user()->username))
            ->orderBy('start_date')->orderByDesc('task_id')->get();
        $sales = User::query()->select(['username', 'department', 'role'])->orderBy('username')->get()
            ->filter(fn (User $user) => RoleAccess::groupForRole($user->role) === RoleAccess::GROUP_SALE)->values();

        return response()->json(['data' => ['tasks' => $tasks, 'sales_team' => $sales]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assigned_to' => ['required', 'array', 'min:1'],
            'assigned_to.*' => ['required', 'string', 'exists:users,username'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'start_time' => ['required_if:all_day,false', 'nullable', 'date_format:H:i'],
            'end_time' => ['required_if:all_day,false', 'nullable', 'date_format:H:i'],
            'all_day' => ['required', 'boolean'],
            'priority' => ['required', 'in:ปกติ,ด่วน,สำคัญมาก'],
        ]);
        if (! $data['all_day']
            && $data['start_date'] === $data['end_date']
            && $data['end_time'] <= $data['start_time']) {
            throw ValidationException::withMessages([
                'end_time' => 'เวลาสิ้นสุดต้องอยู่หลังเวลาเริ่ม เมื่อเป็นงานภายในวันเดียวกัน',
            ]);
        }

        $tasks = DB::transaction(function () use ($request, $data) {
            return collect(array_unique($data['assigned_to']))->map(fn (string $username) => SalesTask::query()->create([
                ...collect($data)->except('assigned_to')->all(),
                'assigned_to' => $username,
                'assigned_by' => $request->user()->username,
                'status' => 'รอดำเนินการ',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        });

        return response()->json(['data' => $tasks], 201);
    }

    public function updateStatus(Request $request, SalesTask $task): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:รอดำเนินการ,กำลังทำ,สำเร็จ,ยกเลิก']]);
        $group = RoleAccess::groupForRole($request->user()->role);
        if (in_array($group, [RoleAccess::GROUP_SALE, RoleAccess::GROUP_DEFAULT], true) && $task->assigned_to !== $request->user()->username) {
            abort(403, 'คุณอัปเดตได้เฉพาะงานที่มอบหมายให้คุณ');
        }
        $task->forceFill([...$data, 'updated_at' => now()])->save();

        return response()->json(['data' => $task->fresh()]);
    }

    public function destroy(SalesTask $task): JsonResponse
    {
        $task->delete();

        return response()->json(status: 204);
    }
}
