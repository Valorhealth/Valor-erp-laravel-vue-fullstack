<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:150'],
            'result' => ['nullable', Rule::in(['success', 'failed'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $filtered = AuditLog::query()
            ->when($data['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $query->where(function (Builder $nested) use ($term): void {
                    $nested->whereRaw('LOWER(COALESCE(username, \'\')) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(description) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(target_id, \'\')) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(CAST(request_data AS TEXT)) LIKE ?', [$term]);
                });
            })
            ->when($data['username'] ?? null, fn (Builder $query, string $username) => $query->where('username', $username))
            ->when($data['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($data['action'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            ->when(($data['result'] ?? null) === 'success', fn (Builder $query) => $query->where('was_successful', true))
            ->when(($data['result'] ?? null) === 'failed', fn (Builder $query) => $query->where('was_successful', false))
            ->when($data['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_at', '>=', $date))
            ->when($data['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_at', '<=', $date));

        $summaryBase = clone $filtered;
        $summary = [
            'total' => (clone $summaryBase)->count(),
            'successful' => (clone $summaryBase)->where('was_successful', true)->count(),
            'failed' => (clone $summaryBase)->where('was_successful', false)->count(),
            'active_users' => (clone $summaryBase)->whereNotNull('username')->distinct()->count('username'),
            'today' => AuditLog::query()->whereDate('occurred_at', today())->count(),
        ];

        return response()->json([
            'data' => (clone $filtered)->orderByDesc('occurred_at')->orderByDesc('audit_id')->paginate($data['per_page'] ?? 25),
            'summary' => $summary,
            'filters' => [
                'users' => AuditLog::query()->whereNotNull('username')->distinct()->orderBy('username')->pluck('username'),
                'categories' => AuditLog::query()->distinct()->orderBy('category')->pluck('category'),
                'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            ],
        ]);
    }
}
