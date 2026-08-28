<?php

namespace Tests\Unit;

use App\Mail\WorkflowNotificationMail;
use App\Models\User;
use App\Services\Auth\PasswordResetService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordResetServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for database service tests.');
        }
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('username'); $table->string('email'); $table->string('password_hash');
            $table->string('first_name')->nullable(); $table->string('last_name')->nullable();
            $table->string('role')->nullable(); $table->string('department')->nullable();
            $table->timestamp('password_changed_at')->nullable(); $table->timestamp('created_at')->nullable();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary(); $table->string('token_hash', 64); $table->string('requested_ip')->nullable();
            $table->timestamp('created_at'); $table->timestamp('expires_at'); $table->timestamp('used_at')->nullable();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id('audit_id'); $table->timestamp('occurred_at'); $table->unsignedBigInteger('user_id')->nullable();
            $table->string('username')->nullable(); $table->string('role')->nullable(); $table->string('department')->nullable();
            $table->string('action'); $table->string('category'); $table->text('description'); $table->string('http_method');
            $table->string('route_uri')->nullable(); $table->string('target_type')->nullable(); $table->string('target_id')->nullable();
            $table->json('request_data')->nullable(); $table->integer('response_status')->nullable(); $table->boolean('was_successful');
            $table->string('ip_address')->nullable(); $table->text('user_agent')->nullable(); $table->decimal('duration_ms')->nullable();
        });
    }

    #[Test]
    public function it_emails_a_one_time_token_and_invalidates_it_after_reset(): void
    {
        Mail::fake();
        config()->set('workflow_notifications.transport', 'smtp');
        config()->set('password_reset.frontend_url', 'https://erp.example.test');
        $user = User::query()->create(['username' => 'sale1', 'email' => 'sale@example.com', 'password_hash' => Hash::make('Oldpass1'), 'created_at' => now()]);

        app(PasswordResetService::class)->requestLink('SALE@example.com', '127.0.0.1');

        $token = null;
        Mail::assertSent(WorkflowNotificationMail::class, function (WorkflowNotificationMail $mail) use (&$token): bool {
            preg_match('/[?&]token=([^&\s]+)/', $mail->bodyText, $matches);
            $token = isset($matches[1]) ? urldecode($matches[1]) : null;
            return $mail->hasTo('sale@example.com') && is_string($token);
        });
        $service = app(PasswordResetService::class);
        $this->assertTrue($service->reset('sale@example.com', (string) $token, 'Newpass123'));
        $this->assertTrue(Hash::check('Newpass123', $user->fresh()->password_hash));
        $this->assertFalse($service->reset('sale@example.com', (string) $token, 'Another123'));
    }
}
