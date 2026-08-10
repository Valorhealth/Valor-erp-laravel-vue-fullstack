<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:4', 'confirmed'],
        ]);
        $user = User::query()->where('username', $data['username'])->where('email', $data['email'])->first();
        if (! $user) {
            throw ValidationException::withMessages(['username' => 'Username หรือ Email ไม่ถูกต้อง']);
        }
        $user->forceFill(['password_hash' => hash('sha256', $data['password'])])->save();

        return response()->json(['message' => 'เปลี่ยนรหัสผ่านสำเร็จ']);
    }
}
