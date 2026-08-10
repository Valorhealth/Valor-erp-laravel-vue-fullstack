<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserManagementController extends Controller
{
    private const ROLES = [
        'Sale (กรอกใบขอเปิดบิล)',
        'Approve (หัวหน้าอนุมัติ)',
        'Admin (สั่งพิมพ์ใบเปิดบิล)',
        'Super Admin (ผู้ดูแลระบบสูงสุด)',
    ];

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $users = User::query()
            ->when($search !== '', fn ($query) => $query->where('username', 'ilike', "%{$search}%")
                ->orWhere('first_name', 'ilike', "%{$search}%")
                ->orWhere('last_name', 'ilike', "%{$search}%"))
            ->orderBy('id')->paginate(min(max($request->integer('per_page', 25), 5), 100));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $user = User::query()->create([
            ...collect($data)->except(['password', 'password_confirmation'])->all(),
            'password_hash' => Hash::make($data['password']),
            'created_at' => now(),
        ]);

        return response()->json(['data' => new UserResource($user)], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate($this->rules($user));
        $updates = collect($data)->except(['password', 'password_confirmation'])->all();
        if (($data['password'] ?? '') !== '') {
            $updates['password_hash'] = Hash::make($data['password']);
        }
        $user->fill($updates)->save();

        return response()->json(['data' => new UserResource($user->fresh())]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['user' => 'ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่']);
        }
        $user->delete();

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    private function rules(?User $user = null): array
    {
        return [
            'username' => ['required', 'string', 'min:3', 'max:255', Rule::unique('users', 'username')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:4', 'confirmed'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(self::ROLES)],
            'department' => ['nullable', 'string', 'max:255'],
        ];
    }
}
