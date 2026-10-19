<?php

namespace Tests\Unit;

use App\Mail\WorkflowNotificationMail;
use App\Models\BillingRecord;
use App\Models\CreditRequest;
use App\Models\FibroscanBooking;
use App\Models\SalesCallActivity;
use App\Models\SalesTask;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\WorkflowEmailNotifier;
use App\Services\Notifications\WorkflowMessageSender;
use App\Services\Notifications\WorkflowRecipientResolver;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkflowEmailNotifierTest extends TestCase
{
    #[Test]
    public function it_sends_operational_events_to_the_selected_recipients(): void
    {
        Mail::fake();
        config()->set('workflow_notifications.enabled', true);
        $actor = new User(['username' => 'manager', 'email' => 'manager@example.com']);
        $actor->id = 1;
        $assignee = new User(['username' => 'sale', 'email' => 'sale@example.com']);
        $recipients = Mockery::mock(WorkflowRecipientResolver::class);
        $recipients->shouldReceive('forTargets')->with(['approve', 'admin'], 'booking')->once()->andReturn(['manager@example.com']);
        $recipients->shouldReceive('forUser')->with($assignee, 'tasks')->once()->andReturn(['sale@example.com']);
        $recipients->shouldReceive('forUsername')->with('sale', 'call_sale')->once()->andReturn(['sale@example.com']);
        $notifier = new WorkflowEmailNotifier(app(AuditLogger::class), $recipients, app(WorkflowMessageSender::class));

        $notifier->bookingRequested(new FibroscanBooking(['booking_id' => 8, 'hospital_name' => 'Hospital']), $actor);
        $notifier->taskAssigned(new SalesTask(['task_id' => 10, 'title' => 'Visit', 'assigned_to' => 'sale']), $actor, $assignee);
        $notifier->salesCallCommented(new SalesCallActivity(['call_id' => 12, 'seller_username' => 'sale', 'sm_comment' => 'Follow up']), $actor);

        Mail::assertSentCount(3);
        Mail::assertSent(WorkflowNotificationMail::class, fn (WorkflowNotificationMail $mail): bool => $mail->hasTo('manager@example.com'));
        Mail::assertSent(WorkflowNotificationMail::class, fn (WorkflowNotificationMail $mail): bool => $mail->hasTo('sale@example.com'));
    }

    #[Test]
    public function it_sends_all_workflow_notifications_to_the_configured_recipient(): void
    {
        Mail::fake();
        config()->set('workflow_notifications.enabled', true);

        $actor = new User([
            'username' => 'workflow-user',
            'email' => 'workflow-user@gmail.com',
            'role' => 'approve',
            'department' => 'Sales',
        ]);
        $actor->id = 9;
        $bill = new BillingRecord([
            'bill_id' => 31,
            'buyer_name' => 'Valor Hospital',
            'created_by' => 'sale-user',
            'total_amount' => 25000,
            'status' => 'รอหัวหน้าอนุมัติ',
            'address_note' => 'Bangkok',
        ]);
        $creditRequest = new CreditRequest([
            'request_id' => 12,
            'company_name' => 'Valor Hospital',
            'created_by' => 'sale-user',
            'requested_credit_limit' => 100000,
        ]);
        $recipients = Mockery::mock(WorkflowRecipientResolver::class);
        $recipients->shouldReceive('forTarget')->with('approve')->once()->andReturn([
            'approve-one@gmail.com',
            'approve-two@gmail.com',
        ]);
        $recipients->shouldReceive('forTarget')->with('approve', 'credit')->once()->andReturn([
            'approve-one@gmail.com',
            'approve-two@gmail.com',
        ]);
        $recipients->shouldReceive('forTarget')->with('admin')->once()->andReturn([
            'admin-one@gmail.com',
            'admin-two@gmail.com',
        ]);
        $recipients->shouldReceive('forTarget')->with('admin', 'credit')->once()->andReturn([
            'admin-one@gmail.com',
            'admin-two@gmail.com',
        ]);
        $recipients->shouldReceive('forUsername')->with('sale-user')->once()->andReturn([
            'sale-user@gmail.com',
        ]);
        $recipients->shouldReceive('forUsername')->with('sale-user', 'credit')->twice()->andReturn([
            'sale-user@gmail.com',
        ]);
        $notifier = new WorkflowEmailNotifier(app(AuditLogger::class), $recipients, app(WorkflowMessageSender::class));

        $notifier->billSubmitted($bill, $actor);
        $notifier->billApproved($bill, $actor);
        $notifier->billRejected($bill, $actor, 'แก้ไขรายละเอียดสินค้า');
        $notifier->creditRequested($creditRequest, $actor);
        $notifier->creditApproved($creditRequest, $actor);
        $notifier->creditRejected($creditRequest, $actor, 'เอกสารไม่ครบ');
        $notifier->creditOpened($creditRequest, $actor);

        Mail::assertSentCount(7);
        Mail::assertSent(
            WorkflowNotificationMail::class,
            fn (WorkflowNotificationMail $mail): bool => $mail->hasTo('approve-one@gmail.com')
                && $mail->hasTo('approve-two@gmail.com')
                && $mail->notificationSubject === '📢 แจ้งเตือนบิลใหม่: จาก workflow-user (ลูกค้า: Valor Hospital)'
                && str_contains($mail->bodyText, 'Sale ผู้เปิดบิล: workflow-user')
                && str_contains($mail->bodyText, 'อีเมล Sale: workflow-user@gmail.com')
                && str_contains($mail->bodyText, 'เลขที่บิล: 31')
                && str_contains($mail->bodyText, 'ยอดรวม: 25,000.00 บาท')
                && str_contains($mail->bodyText, 'ที่อยู่: Bangkok'),
        );
        Mail::assertSent(
            WorkflowNotificationMail::class,
            fn (WorkflowNotificationMail $mail): bool => $mail->hasTo('admin-one@gmail.com')
                && $mail->hasTo('admin-two@gmail.com'),
        );
        Mail::assertSent(
            WorkflowNotificationMail::class,
            fn (WorkflowNotificationMail $mail): bool => $mail->hasTo('sale-user@gmail.com'),
        );
    }
}
