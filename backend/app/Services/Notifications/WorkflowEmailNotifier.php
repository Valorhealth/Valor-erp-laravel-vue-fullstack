<?php

namespace App\Services\Notifications;

use App\Models\BillingRecord;
use App\Models\CreditRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WorkflowEmailNotifier
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly WorkflowRecipientResolver $recipients,
        private readonly WorkflowMessageSender $messageSender,
    ) {}

    public function billSubmitted(BillingRecord $bill, User $actor): void
    {
        if ($bill->status === 'รอแอดมินตรวจสอบ') {
            $this->send(
                $actor,
                $this->recipients->forTarget('admin'),
                "📢 แจ้งเตือนบิลอนุมัติอัตโนมัติ: จาก {$actor->username} (ลูกค้า: {$bill->buyer_name})",
                $this->lines([
                    'เรียน ผู้ดูแลระบบ,',
                    '',
                    'มีบิลที่ผ่านเงื่อนไขอนุมัติอัตโนมัติเข้าสู่ระบบ โปรดตรวจสอบรายละเอียดเบื้องต้นดังนี้:',
                    '',
                    "Sale ผู้เปิดบิล: {$actor->username}",
                    'อีเมล Sale: '.$this->userEmail($actor),
                    "เลขที่บิล: {$bill->bill_id}",
                    "ลูกค้า: {$bill->buyer_name}",
                    'ยอดรวม: '.$this->money($bill->total_amount).' บาท',
                    'ที่อยู่: '.$this->value($bill->address_note),
                    '',
                    'กรุณาเข้าสู่ระบบเพื่อตรวจสอบและดำเนินการในขั้นตอน Admin ต่อไปครับ',
                ]),
                'bill',
                (string) $bill->bill_id,
            );

            return;
        }

        $this->send(
            $actor,
            $this->recipients->forTarget('approve'),
            "📢 แจ้งเตือนบิลใหม่: จาก {$actor->username} (ลูกค้า: {$bill->buyer_name})",
            $this->lines([
                'เรียน ผู้มีอำนาจอนุมัติ,',
                '',
                'มีการส่งคำขอเปิดบิลใหม่เข้าสู่ระบบ โปรดตรวจสอบรายละเอียดเบื้องต้นดังนี้:',
                '',
                "Sale ผู้เปิดบิล: {$actor->username}",
                'อีเมล Sale: '.$this->userEmail($actor),
                "เลขที่บิล: {$bill->bill_id}",
                "ลูกค้า: {$bill->buyer_name}",
                'ยอดรวม: '.$this->money($bill->total_amount).' บาท',
                'ที่อยู่: '.$this->value($bill->address_note),
                '',
                'กรุณาเข้าสู่ระบบเพื่อตรวจสอบและดำเนินการอนุมัติต่อไปครับ',
            ]),
            'bill',
            (string) $bill->bill_id,
        );
    }

    public function billApproved(BillingRecord $bill, User $actor): void
    {
        $this->send(
            $actor,
            $this->recipients->forTarget('admin'),
            "✅ แจ้งเตือนบิลที่อนุมัติแล้ว: โดย {$actor->username} (ลูกค้า: {$bill->buyer_name})",
            $this->lines([
                'เรียน ผู้ดูแลระบบ,',
                '',
                'มีบิลที่ผ่านการอนุมัติและรอดำเนินการในขั้นตอน Admin รายละเอียดดังนี้:',
                '',
                "Approve ผู้อนุมัติ: {$actor->username}",
                'อีเมล Approve: '.$this->userEmail($actor),
                "Sale เจ้าของบิล: {$bill->created_by}",
                "เลขที่บิล: {$bill->bill_id}",
                "ลูกค้า: {$bill->buyer_name}",
                'ยอดรวม: '.$this->money($bill->total_amount).' บาท',
                'ที่อยู่: '.$this->value($bill->address_note),
                '',
                'กรุณาเข้าสู่ระบบเพื่อตรวจสอบและดำเนินการในขั้นตอน Admin ต่อไปครับ',
            ]),
            'bill',
            (string) $bill->bill_id,
        );
    }

    public function billRejected(BillingRecord $bill, User $actor, string $reason): void
    {
        $this->send(
            $actor,
            $this->recipients->forUsername($bill->created_by),
            "↩️ แจ้งเตือนบิลที่ถูกตีกลับ: โดย {$actor->username} (ลูกค้า: {$bill->buyer_name})",
            $this->lines([
                "เรียน {$bill->created_by},",
                '',
                'บิลของคุณถูกตีกลับเพื่อให้แก้ไข รายละเอียดดังนี้:',
                '',
                "Approve ผู้พิจารณา: {$actor->username}",
                'อีเมล Approve: '.$this->userEmail($actor),
                "เลขที่บิล: {$bill->bill_id}",
                "ลูกค้า: {$bill->buyer_name}",
                "เหตุผล: {$reason}",
                '',
                'กรุณาเข้าสู่ระบบเพื่อแก้ไขและส่งอนุมัติอีกครั้งครับ',
            ]),
            'bill',
            (string) $bill->bill_id,
        );
    }

    public function creditRequested(CreditRequest $creditRequest, User $actor): void
    {
        $this->send(
            $actor,
            $this->recipients->forTarget('approve'),
            "📢 แจ้งเตือนคำขอวงเงินใหม่: จาก {$actor->username} (ลูกค้า: {$creditRequest->company_name})",
            $this->lines([
                'เรียน ผู้มีอำนาจอนุมัติ,',
                '',
                'มีการส่งคำขอวงเงินใหม่เข้าสู่ระบบ รายละเอียดดังนี้:',
                '',
                "Sale ผู้ส่งคำขอ: {$actor->username}",
                'อีเมล Sale: '.$this->userEmail($actor),
                "เลขที่คำขอ: {$creditRequest->request_id}",
                "ลูกค้า: {$creditRequest->company_name}",
                'วงเงินที่ขอ: '.$this->money($creditRequest->requested_credit_limit).' บาท',
                '',
                'กรุณาเข้าสู่ระบบเพื่อตรวจสอบและดำเนินการอนุมัติต่อไปครับ',
            ]),
            'credit_request',
            (string) $creditRequest->request_id,
        );
    }

    public function creditApproved(CreditRequest $creditRequest, User $actor): void
    {
        $this->send(
            $actor,
            $this->recipients->forTarget('admin'),
            "✅ แจ้งเตือนคำขอวงเงินที่อนุมัติแล้ว: โดย {$actor->username} (ลูกค้า: {$creditRequest->company_name})",
            $this->lines([
                'เรียน ผู้ดูแลระบบ,',
                '',
                'มีคำขอวงเงินที่ผ่านการอนุมัติและรอดำเนินการในขั้นตอน Admin รายละเอียดดังนี้:',
                '',
                "Approve ผู้อนุมัติ: {$actor->username}",
                'อีเมล Approve: '.$this->userEmail($actor),
                "Sale เจ้าของคำขอ: {$creditRequest->created_by}",
                "เลขที่คำขอ: {$creditRequest->request_id}",
                "ลูกค้า: {$creditRequest->company_name}",
                '',
                'กรุณาเข้าสู่ระบบเพื่อดำเนินการในขั้นตอน Admin ต่อไปครับ',
            ]),
            'credit_request',
            (string) $creditRequest->request_id,
        );
    }

    public function creditRejected(CreditRequest $creditRequest, User $actor, string $reason): void
    {
        $this->send(
            $actor,
            $this->recipients->forUsername($creditRequest->created_by),
            "↩️ แจ้งเตือนคำขอวงเงินที่ไม่ผ่านอนุมัติ: โดย {$actor->username} (ลูกค้า: {$creditRequest->company_name})",
            $this->lines([
                "เรียน {$creditRequest->created_by},",
                '',
                'คำขอวงเงินของคุณไม่ได้รับการอนุมัติ รายละเอียดดังนี้:',
                '',
                "Approve ผู้พิจารณา: {$actor->username}",
                'อีเมล Approve: '.$this->userEmail($actor),
                "เลขที่คำขอ: {$creditRequest->request_id}",
                "ลูกค้า: {$creditRequest->company_name}",
                "เหตุผล: {$reason}",
                '',
                'กรุณาเข้าสู่ระบบเพื่อตรวจสอบและแก้ไขข้อมูลครับ',
            ]),
            'credit_request',
            (string) $creditRequest->request_id,
        );
    }

    /** @param array<int, string> $recipients */
    private function send(User $actor, array $recipients, string $subject, string $body, string $targetType, string $targetId): void
    {
        if (! config('workflow_notifications.enabled', true)) {
            return;
        }

        $recipients = array_values(array_unique(array_filter($recipients)));
        $startedAt = microtime(true);
        $successful = false;
        $error = null;

        try {
            if ($recipients === []) {
                throw new \RuntimeException('ไม่พบบัญชีผู้รับที่มีอีเมลสำหรับ Workflow นี้');
            }

            $this->messageSender->send($recipients, $subject, $body);
            $successful = true;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            Log::warning('ERP workflow email notification failed.', [
                'recipients' => $recipients,
                'subject' => $subject,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'exception' => $exception,
            ]);
        }

        $this->auditLogger->recordSystemEvent(
            actor: $actor,
            action: $successful ? 'email.sent' : 'email.failed',
            category: 'อีเมลแจ้งเตือน',
            description: $successful ? 'ส่งอีเมลแจ้งเตือน Workflow' : 'ส่งอีเมลแจ้งเตือน Workflow ไม่สำเร็จ',
            targetType: $targetType,
            targetId: $targetId,
            data: array_filter([
                'recipients' => $recipients,
                'subject' => $subject,
                'error' => $error,
            ], fn (mixed $value): bool => $value !== null),
            successful: $successful,
            startedAt: $startedAt,
        );
    }

    /** @param array<int, string> $lines */
    private function lines(array $lines): string
    {
        return implode(PHP_EOL, $lines);
    }

    private function userEmail(User $user): string
    {
        return filled($user->email) ? (string) $user->email : '-';
    }

    private function value(mixed $value): string
    {
        return filled($value) ? (string) $value : '-';
    }

    private function money(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2);
    }
}
