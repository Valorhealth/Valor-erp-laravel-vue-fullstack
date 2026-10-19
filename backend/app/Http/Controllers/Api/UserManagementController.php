<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\BillingRecord;
use App\Models\Quotation;
use App\Models\SalesTask;
use App\Models\Salesman;
use App\Models\User;
use App\Services\Authorization\RoleAccess;
use App\Services\Users\UserIdentityUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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
            ->with('salesman:id,salesman_code,salesman_name')
            ->withExists(['signatureAssets as has_signature' => fn ($query) => $query->where('is_current', true)])
            ->when($search !== '', fn ($query) => $query->where('username', 'ilike', "%{$search}%")
                ->orWhere('first_name', 'ilike', "%{$search}%")
                ->orWhere('last_name', 'ilike', "%{$search}%"))
            ->orderBy('id')->paginate(min(max($request->integer('per_page', 25), 5), 100));

        return response()->json($users->through(
            fn (User $user): array => (new UserResource($user))->resolve($request),
        ));
    }

    public function permissionCatalog(Request $request): JsonResponse
    {
        abort_unless(
            RoleAccess::groupForRole($request->user()->role) === RoleAccess::GROUP_SUPER_ADMIN,
            403,
            'เฉพาะ Super Admin เท่านั้นที่กำหนดสิทธิ์รายบัญชีได้',
        );

        return response()->json(['data' => RoleAccess::permissionCatalog()]);
    }

    public function salesmen(): JsonResponse
    {
        return response()->json(['data' => Salesman::query()->select(['id', 'salesman_code', 'salesman_name'])->orderBy('salesman_code')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedData($request);
        if ($data['quotation_signing_authorized']) {
            throw ValidationException::withMessages(['quotation_signing_authorized' => 'กรุณาสร้างบัญชีก่อน แล้วแนบ PNG ลายเซ็นในหน้าแก้ไข จึงจะเปิดสิทธิ์ลงนามได้']);
        }
        $user = User::query()->create([
            ...collect($data)->except(['password', 'password_confirmation'])->all(),
            'password_hash' => Hash::make($data['password']),
            'created_at' => now(),
        ]);

        return response()->json(['data' => new UserResource($user->load('salesman'))], 201);
    }

    public function update(Request $request, User $user, UserIdentityUpdater $identityUpdater): JsonResponse
    {
        $data = $this->validatedData($request, $user);
        if ($data['quotation_signing_authorized'] && ! $user->signatureAssets()->where('is_current', true)->exists()) {
            throw ValidationException::withMessages(['quotation_signing_authorized' => 'ต้องแนบ PNG ลายเซ็นประจำบัญชีก่อนเปิดสิทธิ์ลงนาม']);
        }
        $updates = collect($data)->except(['password', 'password_confirmation'])->all();
        if (($data['password'] ?? '') !== '') {
            $updates['password_hash'] = Hash::make($data['password']);
            $updates['password_changed_at'] = now();
        }
        $user = $identityUpdater->update($user, $updates);

        return response()->json(['data' => new UserResource($user->load('salesman'))]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if(
            RoleAccess::groupForRole($user->role) === RoleAccess::GROUP_SUPER_ADMIN
                && RoleAccess::groupForRole($request->user()->role) !== RoleAccess::GROUP_SUPER_ADMIN,
            403,
            'เฉพาะ Super Admin เท่านั้นที่ลบบัญชี Super Admin ได้',
        );
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['user' => 'ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่']);
        }
        if ($user->signatureAssets()->exists()) {
            throw ValidationException::withMessages([
                'user' => 'บัญชีนี้มีประวัติภาพลายเซ็น จึงลบไม่ได้ ให้ปิดสิทธิ์ลงนามและเปลี่ยน Role เพื่อรักษาหลักฐานเดิม',
            ]);
        }
        if (BillingRecord::query()->where('creator_user_id', $user->id)->exists()
            || Quotation::query()->where('creator_user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'user' => 'บัญชีนี้มีประวัติใบเสนอราคาหรือบิล จึงลบไม่ได้ กรุณาเปลี่ยน Role เพื่อปิดการใช้งานแทน',
            ]);
        }
        if (SalesTask::query()->where('assigned_to_user_id', $user->id)->orWhere('assigned_by_user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'user' => 'บัญชีนี้มีประวัติมอบหมายงานหรือได้รับมอบหมายงาน จึงลบไม่ได้ ให้เปลี่ยน Role เพื่อรักษาประวัติเดิม',
            ]);
        }
        $user->delete();

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    private function rules(?User $user = null): array
    {
        return [
            'username' => ['required', 'string', 'min:3', 'max:255', Rule::unique('users', 'username')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(8)->letters()->numbers()],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\\-\\s]{6,30}$/'],
            'role' => ['required', Rule::in(self::ROLES)],
            'department' => ['nullable', 'string', 'max:255'],
            'salesman_id' => ['nullable', 'integer', 'exists:salesmen,id', Rule::unique('users', 'salesman_id')->ignore($user?->id)],
            'quotation_signing_authorized' => ['required', 'boolean'],
            'permission_overrides' => ['nullable', 'array'],
            'permission_overrides.*' => ['boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private function validatedData(Request $request, ?User $user = null): array
    {
        $data = $request->validate($this->rules($user));
        $isSuperAdmin = RoleAccess::groupForRole($request->user()->role) === RoleAccess::GROUP_SUPER_ADMIN;

        if (! $isSuperAdmin) {
            abort_if(
                $user && RoleAccess::groupForRole($user->role) === RoleAccess::GROUP_SUPER_ADMIN,
                403,
                'เฉพาะ Super Admin เท่านั้นที่แก้ไขบัญชี Super Admin ได้',
            );
            if (RoleAccess::groupForRole($data['role']) === RoleAccess::GROUP_SUPER_ADMIN) {
                throw ValidationException::withMessages([
                    'role' => 'เฉพาะ Super Admin เท่านั้นที่สร้างหรือเลื่อนระดับบัญชีเป็น Super Admin ได้',
                ]);
            }
            unset($data['permission_overrides']);

            return $data;
        }

        $overrides = (array) ($data['permission_overrides'] ?? []);
        if (RoleAccess::groupForRole($data['role']) === RoleAccess::GROUP_SUPER_ADMIN) {
            $data['permission_overrides'] = [];

            return $data;
        }
        $allowedKeys = array_column(RoleAccess::permissionCatalog(), 'key');
        $unknownKeys = array_diff(array_keys($overrides), $allowedKeys);
        if ($unknownKeys !== []) {
            throw ValidationException::withMessages([
                'permission_overrides' => 'พบสิทธิ์ที่ระบบไม่รองรับ: '.implode(', ', $unknownKeys),
            ]);
        }

        $data['permission_overrides'] = collect($overrides)
            ->filter(fn (mixed $enabled): bool => is_bool($enabled))
            ->all();

        return $data;
    }
}
