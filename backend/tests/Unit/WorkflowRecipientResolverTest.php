<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\InAppNotificationPublisher;
use App\Services\Notifications\WorkflowRecipientResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkflowRecipientResolverTest extends TestCase
{
    #[Test]
    public function it_filters_each_user_by_category_and_global_switch(): void
    {
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not installed in this PHP runtime.');
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->string('email')->nullable();
            $table->string('role');
            $table->text('preferences')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id('notification_id');
            $table->integer('user_id');
            $table->string('category');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->string('action_url')->nullable();
            $table->string('dedupe_key')->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        User::query()->create(['username' => 'approver', 'email' => 'approve@example.com', 'role' => 'approve', 'preferences' => ['notification_categories' => ['booking' => false]]]);
        User::query()->create(['username' => 'admin', 'email' => 'admin@example.com', 'role' => 'admin', 'preferences' => []]);
        User::query()->create(['username' => 'sale', 'email' => 'sale@example.com', 'role' => 'sale', 'preferences' => ['notification_categories' => ['tasks' => false]]]);
        $approverWithoutEmail = User::query()->create(['username' => 'approver-no-email', 'email' => null, 'role' => 'approve', 'preferences' => []]);
        $approverInAppOnly = User::query()->create(['username' => 'approver-in-app', 'email' => 'in-app@example.com', 'role' => 'approve', 'preferences' => ['email_notifications' => false]]);

        $resolver = app(WorkflowRecipientResolver::class);
        $this->assertSame(['admin@example.com'], $resolver->forTargets(['approve', 'admin'], 'booking'));
        $this->assertSame(['approve@example.com', 'admin@example.com'], $resolver->forTargets(['approve', 'admin'], 'billing'));
        $this->assertSame([], $resolver->forUsername('sale', 'tasks'));
        $this->assertSame(['sale@example.com'], $resolver->forUsername('sale', 'call_sale'));
        $this->assertSame([(int) $approverWithoutEmail->id], $resolver->userIdsForUsername('approver-no-email'));
        $this->assertContains((int) $approverWithoutEmail->id, $resolver->userIdsForTarget('approve'));
        $this->assertNotContains('in-app@example.com', $resolver->forTarget('approve'));
        $this->assertContains((int) $approverInAppOnly->id, $resolver->userIdsForTarget('approve'));

        app(InAppNotificationPublisher::class)->publishToUsers(
            $resolver->userIdsForTarget('approve'),
            'แจ้งเตือนใบเสนอราคาใหม่',
            'Sale ส่งใบเสนอราคาเพื่อรออนุมัติ',
            'bill',
            '31',
        );
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $approverWithoutEmail->id,
            'target_type' => 'bill',
            'target_id' => '31',
            'action_url' => '/billing-approvals',
        ]);
        $this->assertSame(3, UserNotification::query()->count());
    }
}
