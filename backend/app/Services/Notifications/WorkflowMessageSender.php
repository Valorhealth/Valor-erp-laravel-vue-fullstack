<?php

namespace App\Services\Notifications;

use App\Mail\WorkflowNotificationMail;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

final class WorkflowMessageSender
{
    /** @param array<int, string> $recipients */
    public function send(array $recipients, string $subject, string $body): void
    {
        if (config('workflow_notifications.transport', 'smtp') === 'gmail_api') {
            $this->sendWithGmailApi($recipients, $subject, $body);

            return;
        }

        Mail::to($recipients)->send(new WorkflowNotificationMail($subject, $body));
    }

    /** @param array<int, string> $recipients */
    private function sendWithGmailApi(array $recipients, string $subject, string $body): void
    {
        $clientId = $this->requiredConfig('workflow_notifications.gmail.client_id');
        $clientSecret = $this->requiredConfig('workflow_notifications.gmail.client_secret');
        $refreshToken = $this->requiredConfig('workflow_notifications.gmail.refresh_token');
        $sender = $this->requiredConfig('workflow_notifications.gmail.sender');

        $tokenResponse = $this->request()
            ->asForm()
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ])
            ->throw();
        $accessToken = (string) $tokenResponse->json('access_token');
        if ($accessToken === '') {
            throw new RuntimeException('Google OAuth did not return an access token.');
        }

        $rawMessage = implode("\r\n", [
            'From: '.config('mail.from.name', 'Valor ERP').' <'.$sender.'>',
            'To: '.implode(', ', $recipients),
            'Subject: =?UTF-8?B?'.base64_encode($subject).'?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($body), 76, "\r\n"),
        ]);

        $this->request()
            ->withToken($accessToken)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '='),
            ])
            ->throw();
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->timeout(30)->retry(2, 250, throw: false);
    }

    private function requiredConfig(string $key): string
    {
        $value = trim((string) config($key));
        if ($value === '') {
            throw new RuntimeException("Missing Gmail API configuration: {$key}");
        }

        return $value;
    }
}
