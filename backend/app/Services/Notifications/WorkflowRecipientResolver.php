<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\Authorization\RoleAccess;

class WorkflowRecipientResolver
{
    /** @return array<int, string> */
    public function forTarget(string $target): array
    {
        $groups = config("workflow_notifications.recipient_groups.{$target}", []);
        if (! is_array($groups) || $groups === []) {
            return [];
        }

        return User::query()
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->get(['email', 'role'])
            ->filter(fn (User $user): bool => in_array(RoleAccess::groupForRole($user->role), $groups, true))
            ->pluck('email')
            ->map(fn (mixed $email): string => mb_strtolower(trim((string) $email)))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function forUsername(?string $username): array
    {
        if (blank($username)) {
            return [];
        }

        $email = User::query()->where('username', $username)->value('email');
        $email = mb_strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? [] : [$email];
    }
}
