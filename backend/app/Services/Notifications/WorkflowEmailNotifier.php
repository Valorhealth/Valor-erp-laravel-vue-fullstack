<?php

namespace App\Services\Notifications;

use App\Models\BillingRecord;
use App\Models\CreditRequest;
use App\Models\FibroscanBooking;
use App\Models\Quotation;
use App\Models\QuotationDocumentHelpRequest;
use App\Models\SalesCallActivity;
use App\Models\SalesTask;
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
        private readonly ?InAppNotificationPublisher $inAppNotifications = null,
    ) {}

    public function billSubmitted(BillingRecord $bill, User $actor): void
    {
        if ($bill->status === 'รอแอดมินส่งออก') {
            $this->send(
                $actor,
                $this->recipients->forTarget('admin'),
                "📢 แจ้งเตือนบิลอนุมัติอัตโนมัติ: จาก {$actor->username} (ลูกค้า: {$bill->buyer_name})",
                $this->lines([
                    'เรียน ผู้ดูแลระบบ,',
                    '',
                    'มีบิลที่ผ่านเงื่อนไขอนุมัติอัตโนมัติเข้าสู่ระบบและรอส่งออก Bplus รายละเอียดดังนี้:',
                    '',
                    "Sale ผู้เปิดบิล: {$actor->username}",
                    'อีเมล Sale: '.$this->userEmail($actor),
                    "เลขที่บิล: {$bill->bill_id}",
                    "ลูกค้า: {$bill->buyer_name}",
                    'ยอดรวม: '.$this->money($bill->total_amount).' บาท',
                    'ที่อยู่: '.$this->value($bill->address_note),
                    '',
                    'กรุณาเข้าสู่ระบบเพื่อส่งออกข้อมูลไปยัง Bplus ต่อไปครับ',
                ]),
                'bill_admin',
                (string) $bill->bill_id,
                $this->inAppNotifications ? $this->recipients->userIdsForTarget('admin') : [],
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
            'bill_approval',
            (string) $bill->bill_id,
            $this->inAppNotifications ? $this->recipients->userIdsForTarget('approve') : [],
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
                'มีบิลที่ผ่านการอนุมัติและรอ Admin ส่งออกไปยัง Bplus รายละเอียดดังนี้:',
                '',
                "Approve ผู้อนุมัติ: {$actor->username}",
                'อีเมล Approve: '.$this->userEmail($actor),
                "Sale เจ้าของบิล: {$bill->created_by}",
                "เลขที่บิล: {$bill->bill_id}",
                "ลูกค้า: {$bill->buyer_name}",
                'ยอดรวม: '.$this->money($bill->total_amount).' บาท',
                'ที่อยู่: '.$this->value($bill->address_note),
                'เอกสารที่ต้องจัดเตรียม: '.$this->documentRequirements($bill),
                '',
                'กรุณาเปิดเมนูคิวงานจัดเตรียมเอกสาร เพื่อดำเนินการให้ครบถ้วนครับ',
            ]),
            'bill_admin',
            (string) $bill->bill_id,
            $this->inAppNotifications ? $this->recipients->userIdsForTarget('admin') : [],
        );
    }

    public function billAwaitingCustomer(BillingRecord $bill, User $actor): void
    {
        $this->send(
            $actor,
            $this->recipients->forUsername($bill->created_by),
            "✍️ ใบเสนอราคาอนุมัติแล้ว รอหลักฐานลูกค้า (ลูกค้า: {$bill->buyer_name})",
            $this->lines([
                "เรียน {$bill->created_by},",
                '',
                "ผู้อนุมัติ {$actor->username} อนุมัติใบเสนอราคา Revision R{$bill->revision_no} แล้ว",
                "เลขที่รายการ: {$bill->bill_id}",
                "ลูกค้า: {$bill->buyer_name}",
                '',
                'กรุณาส่งใบเสนอราคาให้ลูกค้า แล้วแนบใบเสนอราคาที่ลูกค้าลงชื่อหรือ PO ต้นฉบับในศูนย์จัดการเอกสาร เพื่อส่งต่อ Admin ครับ',
            ]),
            'bill_customer',
            (string) $bill->bill_id,
            $this->inAppNotifications ? $this->recipients->userIdsForUsername($bill->created_by) : [],
        );
    }

    public function customerEvidenceReceived(BillingRecord $bill, User $actor): void
    {
        $this->send(
            $actor,
            $this->recipients->forTarget('admin'),
            "✅ ได้รับหลักฐานยืนยันจากลูกค้า: {$bill->buyer_name}",
            $this->lines([
                'เรียน ผู้ดูแลระบบ,',
                '',
                "Sale {$actor->username} แนบหลักฐานยืนยันจากลูกค้าแล้ว",
                "เลขที่รายการ: {$bill->bill_id}",
                "ลูกค้า: {$bill->buyer_name}",
                'ยอดรวม: '.$this->money($bill->total_amount).' บาท',
                '',
                'กรุณาเข้าสู่ระบบเพื่อตรวจเอกสารต้นฉบับและดำเนินการขั้นตอน Admin ต่อไปครับ',
            ]),
            'bill_admin',
            (string) $bill->bill_id,
            $this->inAppNotifications ? $this->recipients->userIdsForTarget('admin') : [],
        );
    }

    public function customerAcceptedQuotation(BillingRecord $bill, User $sale, string $signerName): void
    {
        $this->send(
            $sale,
            $this->recipients->forTarget('admin'),
            "✅ ลูกค้ายืนยันใบเสนอราคาแล้ว: {$bill->buyer_name}",
            $this->lines([
                'เรียน ผู้ดูแลระบบ,',
                '',
                "ลูกค้าลงลายมือชื่ออิเล็กทรอนิกส์ผ่าน Customer Portal โดย {$signerName}",
                "เลขที่รายการ: {$bill->bill_id}",
                "ลูกค้า: {$bill->buyer_name}",
                "Sale เจ้าของรายการ: {$bill->created_by}",
                'ยอดรวม: '.$this->money($bill->total_amount).' บาท',
                '',
                'กรุณาเข้าสู่ระบบเพื่อตรวจใบเสนอราคาที่ลงนามและดำเนินการขั้นตอน Admin ต่อไปครับ',
            ]),
            'bill_admin',
            (string) $bill->bill_id,
            $this->inAppNotifications ? $this->recipients->userIdsForTarget('admin') : [],
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
            'bill_revision',
            (string) $bill->bill_id,
            $this->inAppNotifications ? $this->recipients->userIdsForUsername($bill->created_by) : [],
        );
    }

    public function billDeclined(BillingRecord $bill, User $actor, string $reason): void
    {
        $this->send(
            $actor,
            $this->recipients->forUsername($bill->created_by),
            "❌ แจ้งผลไม่อนุมัติบิล: โดย {$actor->username} (ลูกค้า: {$bill->buyer_name})",
            $this->lines([
                "เรียน {$bill->created_by},",
                '',
                'บิลของคุณไม่ได้รับการอนุมัติและปิดขั้นตอนการพิจารณารายการนี้ รายละเอียดดังนี้:',
                '',
                "Approve ผู้พิจารณา: {$actor->username}",
                'อีเมล Approve: '.$this->userEmail($actor),
                "เลขที่บิล: {$bill->bill_id}",
                "ลูกค้า: {$bill->buyer_name}",
                "เหตุผล: {$reason}",
                '',
                'ระบบเก็บรายการนี้ไว้ในประวัติรายการที่ไม่อนุมัติ และใบเสนอราคาจะแสดงลายน้ำไม่อนุมัติครับ',
            ]),
            'bill_history',
            (string) $bill->bill_id,
            $this->inAppNotifications ? $this->recipients->userIdsForUsername($bill->created_by) : [],
        );
    }

    public function creditRequested(CreditRequest $creditRequest, User $actor): void
    {
        $this->send(
            $actor,
            $this->recipients->forTarget('approve', 'credit'),
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
            $this->inAppNotifications ? $this->recipients->userIdsForTarget('approve', 'credit') : [],
        );
    }

    public function creditApproved(CreditRequest $creditRequest, User $actor): void
    {
        $this->send(
            $actor,
            $this->recipients->forTarget('admin', 'credit'),
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
            $this->inAppNotifications ? $this->recipients->userIdsForTarget('admin', 'credit') : [],
        );
    }

    public function creditRejected(CreditRequest $creditRequest, User $actor, string $reason): void
    {
        $this->send(
            $actor,
            $this->recipients->forUsername($creditRequest->created_by, 'credit'),
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
            $this->inAppNotifications ? $this->recipients->userIdsForUsername($creditRequest->created_by, 'credit') : [],
        );
    }

    public function creditOpened(CreditRequest $creditRequest, User $actor): void
    {
        $this->send($actor, $this->recipients->forUsername($creditRequest->created_by, 'credit'),
            "เปิดวงเงินลูกค้าแล้ว: {$creditRequest->company_name}",
            $this->lines(["ลูกค้า: {$creditRequest->company_name}", "รหัสลูกหนี้: {$creditRequest->ar_code}", "ผู้ดำเนินการ: {$actor->username}", 'กรุณาเข้าสู่ระบบเพื่อดูรายละเอียดคำขอวงเงิน']),
            'credit_request', (string) $creditRequest->request_id,
            $this->inAppNotifications ? $this->recipients->userIdsForUsername($creditRequest->created_by, 'credit') : []);
    }

    public function bookingRequested(FibroscanBooking $booking, User $actor): void
    {
        $recipients = $this->recipients->forTargets(['approve', 'admin'], 'booking');
        $this->send($actor, $recipients,
            "คำขอจอง FibroScan ใหม่ #{$booking->booking_id}",
            $this->lines(["Sale: {$actor->username}", "สถานที่: {$booking->hospital_name}", "ช่วงเวลา: {$booking->reserved_from} ถึง {$booking->reserved_until}", 'กรุณาเข้าสู่ระบบเพื่อตรวจสอบคำขอ']),
            'fibroscan_booking', (string) $booking->booking_id,
            $this->inAppNotifications ? $this->recipients->userIdsForTargets(['approve', 'admin'], 'booking') : []);
    }

    public function bookingChanged(FibroscanBooking $booking, User $actor, string $action): void
    {
        if ($booking->booked_by === $actor->username) {
            return;
        }
        $this->send($actor, $this->recipients->forUsername($booking->booked_by, 'booking'),
            "อัปเดตคำขอจอง FibroScan #{$booking->booking_id}: {$action}",
            $this->lines(["รายการจอง #{$booking->booking_id}", "สถานที่: {$booking->hospital_name}", "การดำเนินการ: {$action}", "ผลพิจารณา: {$booking->approval_status}", "สถานะ: {$booking->status}", 'หมายเหตุ: '.$this->value($booking->review_note), 'ดูรายละเอียดและประวัติการแก้ไขในตารางจองเครื่อง']),
            'fibroscan_booking', (string) $booking->booking_id,
            $this->inAppNotifications ? $this->recipients->userIdsForUsername($booking->booked_by, 'booking') : []);
    }

    public function taskAssigned(SalesTask $task, User $actor, User $assignee): void
    {
        if ($task->assigned_to === $actor->username) {
            return;
        }
        $this->send($actor, $this->recipients->forUser($assignee, 'tasks'),
            "มีงานใหม่มอบหมายให้คุณ #{$task->task_id}",
            $this->lines(["งาน: {$task->title}", "ผู้มอบหมาย: {$actor->username}", "กำหนด: {$task->start_date} ถึง {$task->end_date}", 'กรุณาเข้าสู่ระบบเพื่อตรวจสอบงาน']),
            'task', (string) $task->task_id,
            $this->inAppNotifications ? $this->recipients->userIdsForUser($assignee, 'tasks') : []);
    }

    public function taskUpdated(SalesTask $task, User $actor): void
    {
        if ($task->assigned_by === $actor->username) {
            return;
        }
        $this->send($actor, $this->recipients->forUsername($task->assigned_by, 'tasks'),
            "อัปเดตงาน #{$task->task_id}: {$task->status}",
            $this->lines(["งาน: {$task->title}", "ผู้รับผิดชอบ: {$task->assigned_to}", "สถานะ: {$task->status}", 'กรุณาเข้าสู่ระบบเพื่อดูรายละเอียดความคืบหน้า']),
            'task', (string) $task->task_id,
            $this->inAppNotifications ? $this->recipients->userIdsForUsername($task->assigned_by, 'tasks') : []);
    }

    public function salesCallImported(User $actor, string $month, int $rows): void
    {
        $recipients = $this->recipients->forTargets(['approve', 'admin'], 'call_sale');
        $this->send($actor, $recipients,
            "Call Sale เดือน {$month} นำเข้าแล้ว: {$actor->username}",
            $this->lines(["Sale: {$actor->username}", "เดือน: {$month}", "จำนวนข้อมูล: {$rows} แถว", 'ไฟล์ต้นฉบับดูได้จาก Checklist การส่ง Call Sale รายเดือน']),
            'sales_call_import', "{$actor->id}:{$month}",
            $this->inAppNotifications ? $this->recipients->userIdsForTargets(['approve', 'admin'], 'call_sale') : []);
    }

    public function salesCallWebsiteStarted(User $actor, string $month): void
    {
        $recipients = $this->recipients->forTargets(['approve', 'admin'], 'call_sale');
        $this->send($actor, $recipients,
            "Call Sale เดือน {$month} เริ่มกรอกบนเว็บไซต์: {$actor->username}",
            $this->lines(["Sale: {$actor->username}", "เดือน: {$month}", 'มีการบันทึกข้อมูลครั้งแรกบนเว็บไซต์แล้ว ดูรายการได้ใน Checklist การส่ง Call Sale รายเดือน']),
            'sales_call_website', "{$actor->id}:{$month}",
            $this->inAppNotifications ? $this->recipients->userIdsForTargets(['approve', 'admin'], 'call_sale') : []);
    }

    public function salesCallCommented(SalesCallActivity $activity, User $actor): void
    {
        if ($activity->seller_username === $actor->username) {
            return;
        }
        $this->send($actor, $this->recipients->forUsername($activity->seller_username, 'call_sale'),
            "SM แสดงความคิดเห็นใน Call Sale วันที่ {$activity->activity_date}",
            $this->lines(["ผู้แสดงความคิดเห็น: {$actor->username}", "วันที่: {$activity->activity_date}", "ลูกค้า: {$activity->customer_name}", "ความคิดเห็น: {$activity->sm_comment}", 'กรุณาเข้าสู่ระบบเพื่อตรวจสอบรายการ']),
            'sales_call', (string) $activity->call_id,
            $this->inAppNotifications ? $this->recipients->userIdsForUsername($activity->seller_username, 'call_sale') : []);
    }

    public function quotationHelpRequested(QuotationDocumentHelpRequest $helpRequest, Quotation $quotation, User $actor): void
    {
        $this->send(
            $actor,
            $this->recipients->forTarget('admin'),
            "🆘 คำขอความช่วยเหลือใบเสนอราคา {$quotation->qt_no} จาก {$actor->username}",
            $this->lines([
                'เรียน Sale COO / Admin,',
                '',
                'Sale ส่งคำขอให้ช่วยตรวจสอบหรือแก้ไขใบเสนอราคา',
                "เลขที่ใบเสนอราคา: {$quotation->qt_no}",
                "ลูกค้า: {$quotation->buyer_name}",
                "Sale ผู้ร้องขอ: {$actor->username}",
                "Template: {$helpRequest->template_id}",
                "หมายเหตุ: {$helpRequest->request_note}",
                '',
                rtrim((string) config('app.url'), '/')."/quotations/{$quotation->qt_id}/editor?template={$helpRequest->template_id}&mode={$helpRequest->document_mode}",
            ]),
            'quotation_help_request',
            (string) $helpRequest->help_request_id,
            $this->inAppNotifications ? $this->recipients->userIdsForTarget('admin') : [],
        );
    }

    public function quotationHelpUpdated(QuotationDocumentHelpRequest $helpRequest, Quotation $quotation, User $actor): void
    {
        $status = match ($helpRequest->status) {
            'in_progress' => 'Sale COO รับเรื่องแล้ว',
            'resolved' => 'Sale COO ดำเนินการเสร็จแล้ว',
            'cancelled' => 'คำขอถูกยกเลิก',
            default => $helpRequest->status,
        };

        $this->send(
            $actor,
            $this->recipients->forUsername($helpRequest->requested_by),
            "อัปเดตคำขอช่วยเหลือใบเสนอราคา {$quotation->qt_no}: {$status}",
            $this->lines([
                "ใบเสนอราคา: {$quotation->qt_no}",
                "ลูกค้า: {$quotation->buyer_name}",
                "สถานะ: {$status}",
                "ผู้ดำเนินการ: {$actor->username}",
                'หมายเหตุผลการดำเนินการ: '.$this->value($helpRequest->resolution_note),
                '',
                rtrim((string) config('app.url'), '/')."/quotations/{$quotation->qt_id}/editor?template={$helpRequest->template_id}&mode={$helpRequest->document_mode}",
            ]),
            'quotation_help_request',
            (string) $helpRequest->help_request_id,
            $this->inAppNotifications ? $this->recipients->userIdsForUsername($helpRequest->requested_by) : [],
        );
    }

    /** @param array<int, string> $recipients */
    private function send(User $actor, array $recipients, string $subject, string $body, string $targetType, string $targetId, array $inAppUserIds = []): void
    {
        $recipients = array_values(array_unique(array_filter($recipients)));
        $this->inAppNotifications?->publishToUsers($inAppUserIds, $subject, $body, $targetType, $targetId);

        if (! config('workflow_notifications.enabled', true)) {
            return;
        }
        $startedAt = microtime(true);
        $successful = false;
        $error = null;

        try {
            if ($recipients === []) {
                return;
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

    private function documentRequirements(BillingRecord $bill): string
    {
        $labels = [
            'quotation' => 'ใบเสนอราคา',
            'power_of_attorney' => 'Draft หนังสือมอบอำนาจ',
            'company_affidavit' => 'หนังสือรับรองบริษัท',
            'vat_registration' => 'ภ.พ.20',
            'grantor_id_copies' => 'สำเนาบัตรผู้มอบอำนาจ',
            'representative_id_copy' => 'สำเนาบัตรผู้แทน',
            'product_documents' => 'เอกสารสินค้า/ใบอนุญาต/Catalog',
            'other' => 'เอกสารอื่นตามข้อกำหนด',
        ];

        return collect((array) $bill->submission_document_requirements)
            ->map(fn (string $type): string => $labels[$type] ?? $type)
            ->implode(', ') ?: 'ใบเสนอราคา';
    }
}
