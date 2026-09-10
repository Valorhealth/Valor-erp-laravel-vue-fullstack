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
        $canAssignTasks = RoleAccess::canUser($request->user(), 'approve_bills', 'system_admin');
        $tasks = SalesTask::query()
            ->when(in_array($group, [RoleAccess::GROUP_SALE, RoleAccess::GROUP_DEFAULT], true), fn ($query) => $query->where(
                fn ($owned) => $owned->where('assigned_to_user_id', $request->user()->id)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('assigned_to_user_id')->where('assigned_to', $request->user()->username))
            ))
            ->orderBy('start_date')->orderByDesc('task_id')->get();
        $assignableUsers = User::query()
            ->select(['id', 'username', 'first_name', 'last_name', 'department', 'role'])
            ->orderBy('role')
            ->orderBy('username')
            ->get()
            ->when(
                ! $canAssignTasks,
                fn ($users) => $users
                    ->filter(fn (User $user) => RoleAccess::groupForRole($user->role) === RoleAccess::GROUP_SALE)
                    ->values(),
            );

        return response()->json(['data' => ['tasks' => $tasks, 'assignable_users' => $assignableUsers]]);
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
            $assignees = User::query()->whereIn('username', array_unique($data['assigned_to']))->get()->keyBy('username');

            return collect(array_unique($data['assigned_to']))->map(function (string $username) use ($request, $data, $assignees): SalesTask {
                $assignee = $assignees->get($username);

                return SalesTask::query()->create([
                    ...collect($data)->except('assigned_to')->all(),
                    'assigned_to' => $username,
                    'assigned_to_user_id' => $assignee->id,
                    'assigned_by' => $request->user()->username,
                    'assigned_by_user_id' => $request->user()->id,
                    'status' => 'รอดำเนินการ',
                    'progress_updates' => [[
                        'update_id' => (string) \Illuminate\Support\Str::uuid(),
                        'status' => 'รอดำเนินการ',
                        'progress_note' => 'มอบหมายงานให้ '.$username,
                        'updated_by' => $request->user()->username,
                        'created_at' => now()->toIso8601String(),
                    ]],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        });

        return response()->json(['data' => $tasks], 201);
    }

    public function updateStatus(Request $request, SalesTask $task): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:รอดำเนินการ,กำลังดำเนินการ,สำเร็จ,ยกเลิก'],
            'progress_note' => ['required', 'string', 'min:2', 'max:5000'],
        ]);
        $group = RoleAccess::groupForRole($request->user()->role);
        if (in_array($group, [RoleAccess::GROUP_SALE, RoleAccess::GROUP_DEFAULT], true)
            && (int) ($task->assigned_to_user_id ?? 0) !== (int) $request->user()->id
            && ! ($task->assigned_to_user_id === null && $task->assigned_to === $request->user()->username)) {
            abort(403, 'คุณอัปเดตได้เฉพาะงานที่มอบหมายให้คุณ');
        }
        DB::transaction(function () use ($request, $task, $data): void {
            $task = SalesTask::query()->lockForUpdate()->findOrFail($task->task_id);
            $updates = $task->progress_updates ?? [];
            $updates[] = [
                'update_id' => (string) \Illuminate\Support\Str::uuid(),
                'status' => $data['status'],
                'progress_note' => trim($data['progress_note']),
                'updated_by' => $request->user()->username,
                'created_at' => now()->toIso8601String(),
            ];
            $task->forceFill([
                'status' => $data['status'],
                'progress_updates' => $updates,
                'updated_at' => now(),
            ])->save();
        });

        return response()->json(['data' => $task->fresh()]);
    }

    public function destroy(SalesTask $task): JsonResponse
    {
        $task->delete();

        return response()->json(status: 204);
    }
}
