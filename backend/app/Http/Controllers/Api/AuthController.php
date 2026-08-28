<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\LegacyPasswordVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    public function login(LoginRequest $request, LegacyPasswordVerifier $verifier): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::query()->where('username', $credentials['username'])->first();
        $authenticated = $user !== null
            && $verifier->verifyAndUpgrade($user, $credentials['password']);

        $this->logAttempt($credentials['username'], $authenticated ? 'Success' : 'Failed');

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'username' => ['ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'],
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('password_authenticated_at', now()->getTimestamp());

        return response()->json(['data' => new UserResource($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'ออกจากระบบแล้ว']);
    }

    private function logAttempt(string $username, string $status): void
    {
        try {
            DB::table('login_logs')->insert([
                'username' => $username,
                'status' => $status,
                'login_time' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
