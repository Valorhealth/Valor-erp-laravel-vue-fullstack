<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    #[Test]
    public function it_respects_the_master_switch_and_each_category_with_legacy_defaults(): void
    {
        $service = new NotificationPreferences;
        $user = new User;
        $user->forceFill(['preferences' => []]);
        $this->assertTrue($service->allows($user, 'billing'));
        $this->assertTrue($service->allows($user, 'booking'));

        $user->forceFill(['preferences' => [
            'email_notifications' => true,
            'notification_categories' => ['booking' => false, 'tasks' => false],
        ]]);
        $this->assertTrue($service->allows($user, 'billing'));
        $this->assertFalse($service->allows($user, 'booking'));
        $this->assertFalse($service->allows($user, 'tasks'));

        $user->forceFill(['preferences' => ['email_notifications' => false]]);
        $this->assertFalse($service->allows($user, 'billing'));
        $this->assertTrue($service->allowsInApp($user, 'billing'));
        $this->assertFalse($service->allows($user, 'call_sale'));
        $this->assertFalse($service->allows($user, 'unknown'));

        $user->forceFill(['preferences' => [
            'email_notifications' => true,
            'in_app_notifications' => false,
        ]]);
        $this->assertTrue($service->allows($user, 'billing'));
        $this->assertFalse($service->allowsInApp($user, 'billing'));
    }
}
