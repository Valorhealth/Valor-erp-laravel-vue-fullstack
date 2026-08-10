<?php

namespace Tests\Unit;

use App\Services\Notifications\WorkflowMessageSender;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkflowMessageSenderTest extends TestCase
{
    #[Test]
    public function it_sends_a_workflow_message_through_the_gmail_api(): void
    {
        config()->set('workflow_notifications.transport', 'gmail_api');
        config()->set('workflow_notifications.gmail', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'refresh_token' => 'refresh-token',
            'sender' => 'sukirin.valorhealth@gmail.com',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://gmail.googleapis.com/*' => Http::response(['id' => 'message-id']),
        ]);

        app(WorkflowMessageSender::class)->send(
            ['approve@example.com'],
            'แจ้งเตือนบิลใหม่',
            'เลขที่บิล: 31',
        );

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send'
            && $request->hasHeader('Authorization', 'Bearer access-token')
            && is_string($request['raw'])
            && $request['raw'] !== ''
        );
    }
}
