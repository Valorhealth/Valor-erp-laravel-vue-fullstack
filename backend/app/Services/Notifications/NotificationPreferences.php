<?php

namespace App\Services\Notifications;

use App\Models\User;

final class NotificationPreferences
{
    public const DEFAULT_CATEGORIES = [
        'billing' => true,
        'credit' => true,
        'booking' => true,
        'tasks' => true,
        'call_sale' => true,
    ];

    public function allows(User $user, string $category): bool
    {
        $preferences = (array) ($user->preferences ?? []);
        $categories = (array) ($preferences['notification_categories'] ?? []);

        return ($preferences['email_notifications'] ?? true) === true
            && array_key_exists($category, self::DEFAULT_CATEGORIES)
            && ($categories[$category] ?? true) === true;
    }

    public function allowsInApp(User $user, string $category): bool
    {
        $preferences = (array) ($user->preferences ?? []);
        $categories = (array) ($preferences['notification_categories'] ?? []);

        return ($preferences['in_app_notifications'] ?? true) === true
            && array_key_exists($category, self::DEFAULT_CATEGORIES)
            && ($categories[$category] ?? true) === true;
    }
}
