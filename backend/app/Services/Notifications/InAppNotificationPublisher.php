<?php

namespace App\Services\Notifications;

use App\Models\BillingRecord;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Authorization\RoleAccess;

final class InAppNotificationPublisher
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    /** @param array<int, string> $emails */
    public function publishToEmails(array $emails, string $title, string $body, string $targetType, string $targetId): void
    {
        $normalized = collect($emails)
            ->map(fn (string $email): string => mb_strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values();
        if ($normalized->isEmpty()) {
            return;
        }

        User::query()->whereNotNull('email')->get(['id', 'email', 'role', 'permission_overrides', 'preferences'])
            ->filter(fn (User $user): bool => $normalized->contains(mb_strtolower(trim((string) $user->email))))
            ->each(fn (User $user) => $this->publish($user, $title, $body, $targetType, $targetId));
    }

    /** @param array<int, int> $userIds */
    public function publishToUsers(array $userIds, string $title, string $body, string $targetType, string $targetId): void
    {
        $ids = collect($userIds)->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        User::query()->whereKey($ids)->get(['id', 'role', 'permission_overrides', 'preferences'])
            ->each(fn (User $user) => $this->publish($user, $title, $body, $targetType, $targetId));
    }

    public function syncPendingApprovals(User $user): void
    {
        if (! RoleAccess::canUser($user, 'approve_bills') || ! $this->preferences->allowsInApp($user, 'billing')) {
            return;
        }

        BillingRecord::query()
            ->where('status', 'รอหัวหน้าอนุมัติ')
            ->latest('submitted_at')
            ->limit(100)
            ->get(['bill_id', 'buyer_name', 'created_by', 'total_amount'])
            ->each(function (BillingRecord $bill) use ($user): void {
                $this->publish(
                    $user,
                    "แจ้งเตือนบิลใหม่: จาก {$bill->created_by} (ลูกค้า: {$bill->buyer_name})",
                    "Sale ผู้เปิดบิล: {$bill->created_by}\nเลขที่บิล: {$bill->bill_id}\nลูกค้า: {$bill->buyer_name}\nยอดรวม: ".number_format((float) $bill->total_amount, 2).' บาท',
                    'bill_approval',
                    (string) $bill->bill_id,
                );
            });
    }

    private function publish(User $user, string $title, string $body, string $targetType, string $targetId): void
    {
        if (! $this->preferences->allowsInApp($user, $this->category($targetType))) {
            return;
        }
        $cleanTitle = trim((string) preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $title));
        UserNotification::query()->firstOrCreate(
            ['dedupe_key' => hash('sha256', implode('|', [$user->id, $targetType, $targetId, $cleanTitle]))],
            [
                'user_id' => $user->id,
                'category' => $this->category($targetType),
                'title' => mb_substr($cleanTitle, 0, 500),
                'body' => mb_substr(trim($body), 0, 4000),
                'target_type' => $targetType,
                'target_id' => $targetId,
                'action_url' => $this->actionUrl($user, $targetType),
                'created_at' => now(),
            ],
        );
    }

    private function category(string $targetType): string
    {
        return match ($targetType) {
            'bill', 'bill_approval', 'bill_admin', 'bill_customer', 'bill_revision', 'bill_history' => 'billing',
            'credit_request' => 'credit',
            'fibroscan_booking' => 'booking',
            'task' => 'tasks',
            'sales_call', 'sales_call_import', 'sales_call_website' => 'call_sale',
            'quotation_help_request' => 'billing',
            'document_preparation' => 'billing',
            default => 'workflow',
        };
    }

    private function actionUrl(User $user, string $targetType): string
    {
        return match ($targetType) {
            'bill' => RoleAccess::canUser($user, 'system_admin') ? '/document-preparation-queue'
                : (RoleAccess::canUser($user, 'approve_bills') ? '/billing-approvals' : '/sales-documents'),
            'bill_approval' => '/billing-approvals',
            'bill_admin' => '/document-preparation-queue',
            'bill_customer' => '/sales-documents',
            'bill_revision' => '/bills/edit',
            'bill_history' => '/billing-records/my',
            'credit_request' => '/credit-requests',
            'fibroscan_booking' => '/fibroscan-bookings',
            'task' => '/calendar',
            'sales_call', 'sales_call_import', 'sales_call_website' => RoleAccess::canUser($user, 'sales_call_report') ? '/sales-call-report' : '/sales-call-entry',
            'quotation_help_request' => RoleAccess::canUser($user, 'system_admin') ? '/document-center' : '/sales-documents',
            'document_preparation' => '/my-document-preparation-status',
            default => '/notice-board',
        };
    }
}
