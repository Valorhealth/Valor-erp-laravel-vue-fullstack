<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Auth\LegacyPasswordVerifier;
use App\Services\Authorization\RoleAccess;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Users\UserIdentityUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class UserSettingsController extends Controller
{
    private const DEFAULTS = [
        'language' => 'th',
        'document_language' => 'th',
        'date_format' => 'DD/MM/YYYY',
        'calendar_system' => 'buddhist',
        'timezone' => 'Asia/Bangkok',
        'time_format' => '24h',
        'number_format' => 'en-US',
        'default_path' => '/notice-board',
        'table_density' => 'comfortable',
        'rows_per_page' => 50,
        'sidebar_collapsed' => false,
        'font_scale' => 'normal',
        'high_contrast' => false,
        'reduce_motion' => false,
        'in_app_notifications' => true,
        'email_notifications' => true,
        'notification_categories' => NotificationPreferences::DEFAULT_CATEGORIES,
    ];

    public function show(Request $request): JsonResponse
    {
        $saved = (array) ($request->user()->preferences ?? []);
        // th-TH and en-US both render 1,234.56 with Arabic digits. Migrate the
        // legacy value to the explicit international format exposed by the UI.
        if (($saved['number_format'] ?? null) === 'th-TH') {
            $saved['number_format'] = 'en-US';
        }

        return response()->json(['data' => [
            ...self::DEFAULTS,
            ...$saved,
            'notification_categories' => [
                ...NotificationPreferences::DEFAULT_CATEGORIES,
                ...(array) ($saved['notification_categories'] ?? []),
            ],
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'language' => ['required', Rule::in(['th', 'en'])],
            'document_language' => ['required', Rule::in(['th', 'en'])],
            'date_format' => ['required', Rule::in(['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'])],
            'calendar_system' => ['required', Rule::in(['buddhist', 'gregorian'])],
            'timezone' => ['required', Rule::in(['Asia/Bangkok', 'UTC'])],
            'time_format' => ['required', Rule::in(['24h', '12h'])],
            'number_format' => ['required', Rule::in(['en-US', 'th-TH-u-nu-thai', 'de-DE'])],
            'default_path' => ['required', 'string', 'max:255'],
            'table_density' => ['required', Rule::in(['comfortable', 'compact'])],
            'rows_per_page' => ['required', 'integer', Rule::in([25, 50, 100])],
            'sidebar_collapsed' => ['required', 'boolean'],
            'font_scale' => ['required', Rule::in(['small', 'normal', 'large'])],
            'high_contrast' => ['required', 'boolean'],
            'reduce_motion' => ['required', 'boolean'],
            'in_app_notifications' => ['required', 'boolean'],
            'email_notifications' => ['required', 'boolean'],
            'notification_categories' => ['sometimes', 'array:billing,credit,booking,tasks,call_sale'],
            'notification_categories.*' => ['boolean'],
        ]);

        $allowedPaths = collect(RoleAccess::detailsForUser($request->user())['menu'])->pluck('path')->push('/notice-board');
        if (! $allowedPaths->contains($data['default_path'])) {
            throw ValidationException::withMessages(['default_path' => 'หน้าหลักที่เลือกไม่อยู่ในสิทธิ์ของบัญชีนี้']);
        }

        $previous = (array) ($request->user()->preferences ?? []);
        $data['notification_categories'] = [
            ...NotificationPreferences::DEFAULT_CATEGORIES,
            ...(array) ($previous['notification_categories'] ?? []),
            ...(array) ($data['notification_categories'] ?? []),
        ];
        $request->user()->forceFill(['preferences' => $data])->save();

        return response()->json([
            'data' => new UserResource($request->user()->fresh()),
            'message' => 'บันทึกการตั้งค่าส่วนบุคคลเรียบร้อยแล้ว',
        ]);
    }

    public function updatePassword(Request $request, LegacyPasswordVerifier $passwordVerifier): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if (! $passwordVerifier->verifyAndUpgrade($request->user(), $data['current_password'])) {
            throw ValidationException::withMessages([
                'current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง',
            ]);
        }

        $request->user()->forceFill([
            'password_hash' => Hash::make($data['password']),
            'password_changed_at' => now(),
        ])->save();
        $request->session()->put('password_authenticated_at', now()->getTimestamp());

        return response()->json(['message' => 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว']);
    }

    public function updatePhone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\\-\\s]{6,30}$/'],
        ]);
        $request->user()->forceFill(['phone' => trim((string) ($data['phone'] ?? '')) ?: null])->save();

        return response()->json([
            'data' => new UserResource($request->user()->fresh()),
            'message' => 'บันทึกเบอร์โทรศัพท์เรียบร้อยแล้ว',
        ]);
    }

    public function updateProfile(
        Request $request,
        LegacyPasswordVerifier $passwordVerifier,
        UserIdentityUpdater $identityUpdater,
    ): JsonResponse {
        $user = $request->user();
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:255', 'not_regex:/\s/', Rule::unique('users', 'username')->ignore($user->id)],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\-\s]{6,30}$/'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'representative_address' => ['nullable', 'string', 'max:2000'],
            'national_id' => ['nullable', 'digits:13'],
            'national_id_issued_at' => ['nullable', 'date_format:Y-m-d'],
            'national_id_expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:national_id_issued_at'],
            'current_password' => ['nullable', 'string', 'max:255'],
        ]);
        $username = trim($data['username']);
        $email = mb_strtolower(trim($data['email']));
        $nationalId = preg_replace('/\D/', '', (string) ($data['national_id'] ?? '')) ?: null;
        $address = trim((string) ($data['representative_address'] ?? '')) ?: null;
        $sensitiveChanged = $username !== $user->username
            || $email !== mb_strtolower((string) $user->email)
            || $nationalId !== ($user->national_id ?: null)
            || $address !== ($user->representative_address ?: null);
        if ($sensitiveChanged && ! $passwordVerifier->verifyAndUpgrade($user, (string) ($data['current_password'] ?? ''))) {
            throw ValidationException::withMessages([
                'current_password' => 'กรุณายืนยันรหัสผ่านปัจจุบันเพื่อเปลี่ยน Username, Email, ที่อยู่ หรือเลขบัตรประชาชน',
            ]);
        }

        $updated = $identityUpdater->update($user, [
            'username' => $username,
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'email' => $email,
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'job_title' => trim((string) ($data['job_title'] ?? '')) ?: null,
            'representative_address' => $address,
            'national_id' => $nationalId,
            'national_id_issued_at' => $data['national_id_issued_at'] ?? null,
            'national_id_expires_at' => $data['national_id_expires_at'] ?? null,
        ]);
        if ($sensitiveChanged) {
            $request->session()->put('password_authenticated_at', now()->getTimestamp());
        }

        return response()->json([
            'data' => new UserResource($updated),
            'message' => 'บันทึกข้อมูลบัญชีเรียบร้อยแล้ว',
        ]);
    }
}
