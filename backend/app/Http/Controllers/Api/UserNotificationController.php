<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use App\Services\Notifications\InAppNotificationPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class UserNotificationController extends Controller
{
    public function index(Request $request, InAppNotificationPublisher $publisher): JsonResponse
    {
        $user = $request->user();
        $syncKey = "notifications:pending-sync:{$user->id}";
        if (Cache::add($syncKey, true, now()->addMinutes(5))) {
            $publisher->syncPendingApprovals($user);
        }
        $query = UserNotification::query()->where('user_id', $user->id);

        return response()->json([
            'data' => (clone $query)->latest('created_at')->limit(40)->get(),
            'unread_count' => (clone $query)->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, UserNotification $notification): JsonResponse
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 404);
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => $notification->fresh()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['updated' => $updated]]);
    }
}
