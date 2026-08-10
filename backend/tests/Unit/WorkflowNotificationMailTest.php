<?php

namespace Tests\Unit;

use App\Mail\WorkflowNotificationMail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkflowNotificationMailTest extends TestCase
{
    #[Test]
    public function it_renders_the_workflow_subject_and_plain_text_body(): void
    {
        $mail = new WorkflowNotificationMail('[ERP] Test notification', "Bill: 31\nStatus: approved");

        $this->assertSame('[ERP] Test notification', $mail->envelope()->subject);
        $this->assertStringContainsString('Bill: 31', $mail->render());
        $this->assertStringContainsString('Status: approved', $mail->render());
    }
}
