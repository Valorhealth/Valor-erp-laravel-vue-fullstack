<?php

namespace App\Http\Middleware;

use App\Services\Authorization\RoleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRoleAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        abort_unless(
            $user !== null && RoleAccess::canUser($user, ...$abilities),
            Response::HTTP_FORBIDDEN,
            'บัญชีนี้ไม่มีสิทธิ์ใช้งานส่วนนี้',
        );

        return $next($request);
    }
}
