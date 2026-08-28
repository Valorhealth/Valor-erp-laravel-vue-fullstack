<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\WorkflowMessageSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

final class PasswordResetService
{
    public function __construct(
        private readonly WorkflowMessageSender $sender,
        private readonly AuditLogger $audit,
    ) {}

    public function requestLink(string $email, ?string $ipAddress): void
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first();
        if (! $user || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $plainToken = Str::random(80);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token_hash' => hash('sha256', $plainToken),
                'requested_ip' => $ipAddress,
                'created_at' => now(),
                'expires_at' => now()->addMinutes(max(5, (int) config('password_reset.expires_minutes', 30))),
                'used_at' => null,
            ],
        );

        $query = http_build_query(['email' => $user->email, 'token' => $plainToken]);
        $url = config('password_reset.frontend_url').'/reset-password?'.$query;
        $body = implode("\n", [
            "เรียน {$user->first_name} {$user->last_name}",
            '',
            'ระบบได้รับคำขอเปลี่ยนรหัสผ่านบัญชี Valor ERP ของคุณ',
            'กรุณาเปิดลิงก์ด้านล่างเพื่อกำหนดรหัสผ่านใหม่:',
            $url,
            '',
            'ลิงก์นี้ใช้ได้เพียงครั้งเดียวและหมดอายุภายใน '.config('password_reset.expires_minutes', 30).' นาที',
            'หากคุณไม่ได้เป็นผู้ส่งคำขอ โปรดเพิกเฉยต่ออีเมลฉบับนี้และแจ้งผู้ดูแลระบบ',
        ]);

        try {
            $this->sender->send([$user->email], 'ตั้งรหัสผ่านใหม่ — Valor ERP', $body);
            $this->audit->recordSystemEvent($user, 'auth.password_reset_requested', 'การเข้าสู่ระบบ', 'ส่งลิงก์ตั้งรหัสผ่านใหม่', 'user', (string) $user->id);
        } catch (Throwable $exception) {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $this->audit->recordSystemEvent($user, 'auth.password_reset_email_failed', 'การเข้าสู่ระบบ', 'ส่งลิงก์ตั้งรหัสผ่านใหม่ไม่สำเร็จ', 'user', (string) $user->id, successful: false);
            report($exception);
        }
    }

    public function reset(string $email, string $plainToken, string $password): bool
    {
        return DB::transaction(function () use ($email, $plainToken, $password): bool {
            $record = DB::table('password_reset_tokens')
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
                ->lockForUpdate()
                ->first();

            if (! $record || $record->used_at !== null || now()->greaterThan($record->expires_at)) {
                return false;
            }
            if (! hash_equals((string) $record->token_hash, hash('sha256', $plainToken))) {
                return false;
            }

            $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->lockForUpdate()->first();
            if (! $user) {
                return false;
            }

            $user->forceFill([
                'password_hash' => Hash::make($password),
                'password_changed_at' => now(),
            ])->save();
            DB::table('password_reset_tokens')->where('email', $record->email)->update(['used_at' => now()]);
            $this->audit->recordSystemEvent($user, 'auth.password_reset_completed', 'การเข้าสู่ระบบ', 'ตั้งรหัสผ่านใหม่สำเร็จ', 'user', (string) $user->id);

            return true;
        });
    }
}
