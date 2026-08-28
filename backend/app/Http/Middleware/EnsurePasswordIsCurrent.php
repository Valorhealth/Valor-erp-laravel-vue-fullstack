<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePasswordIsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $changedAt = $request->user()?->password_changed_at?->getTimestamp();
        $authenticatedAt = (int) $request->session()->get('password_authenticated_at', 0);
        if ($changedAt !== null && $authenticatedAt < $changedAt) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'รหัสผ่านถูกเปลี่ยน กรุณาเข้าสู่ระบบอีกครั้ง'], 401);
        }

        return $next($request);
    }
}
