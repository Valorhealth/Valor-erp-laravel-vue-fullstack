<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Services\Authorization\RoleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $group = RoleAccess::groupForRole($request->user()->role);
        $includeInactive = $request->boolean('include_inactive') && ! in_array($group, [RoleAccess::GROUP_SALE, RoleAccess::GROUP_DEFAULT], true);
        $notices = Notice::query()
            ->when(! $includeInactive, fn ($query) => $query->where(fn ($nested) => $nested->where('is_active', true)->orWhereNull('is_active')))
            ->orderByDesc('is_pinned')
            ->orderByRaw('meeting_date NULLS LAST')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $notices]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:10000'],
            'notice_type' => ['required', 'in:ประกาศทั่วไป,นัดประชุม,แจ้งเตือนด่วน'],
            'meeting_date' => ['nullable', 'date'],
            'meeting_time' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:1000'],
            'is_pinned' => ['boolean'],
        ]);
        $notice = Notice::query()->create([
            ...$data,
            'is_active' => true,
            'created_by' => $request->user()->username,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => $notice], 201);
    }

    public function toggle(Request $request, Notice $notice): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $notice->forceFill([...$data, 'updated_at' => now()])->save();

        return response()->json(['data' => $notice->fresh()]);
    }
}
