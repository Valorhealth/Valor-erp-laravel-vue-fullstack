<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class PasswordResetController extends Controller
{
    public function requestLink(Request $request, PasswordResetService $service): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);
        $service->requestLink($data['email'], $request->ip());

        return response()->json(['message' => 'หากอีเมลนี้มีบัญชีอยู่ในระบบ เราได้ส่งลิงก์ตั้งรหัสผ่านใหม่ให้แล้ว']);
    }

    public function reset(Request $request, PasswordResetService $service): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'min:40', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        if (! $service->reset($data['email'], $data['token'], $data['password'])) {
            throw ValidationException::withMessages([
                'token' => ['ลิงก์ตั้งรหัสผ่านไม่ถูกต้อง ถูกใช้แล้ว หรือหมดอายุ กรุณาขอลิงก์ใหม่'],
            ]);
        }

        return response()->json(['message' => 'เปลี่ยนรหัสผ่านสำเร็จ']);
    }
}
