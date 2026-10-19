<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\Authorization\RoleAccess;

class WorkflowRecipientResolver
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    /** @return array<int, string> */
    public function forTarget(string $target, string $category = 'billing'): array
    {
        return $this->forTargets([$target], $category);
    }

    /** @param array<int, string> $targets
     * @return array<int, string>
     */
    public function forTargets(array $targets, string $category): array
    {
        $groups = collect($targets)
            ->flatMap(fn (string $target): array => (array) config("workflow_notifications.recipient_groups.{$target}", []))
            ->unique()->values()->all();
        if ($groups === []) {
            return [];
        }

        return User::query()
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->get(['email', 'role', 'preferences'])
            ->filter(fn (User $user): bool => $this->preferences->allows($user, $category)
                && in_array(RoleAccess::groupForRole($user->role), $groups, true))
            ->pluck('email')
            ->map(fn (mixed $email): string => mb_strtolower(trim((string) $email)))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<int, string> $targets
     * @return array<int, int>
     */
    public function userIdsForTargets(array $targets, string $category): array
    {
        $groups = collect($targets)
            ->flatMap(fn (string $target): array => (array) config("workflow_notifications.recipient_groups.{$target}", []))
            ->unique()->values()->all();
        if ($groups === []) {
            return [];
        }

        return User::query()
            ->get(['id', 'role', 'preferences'])
            ->filter(fn (User $user): bool => $this->preferences->allowsInApp($user, $category)
                && in_array(RoleAccess::groupForRole($user->role), $groups, true))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    public function userIdsForTarget(string $target, string $category = 'billing'): array
    {
        return $this->userIdsForTargets([$target], $category);
    }

    /** @return array<int, string> */
    public function forUsername(?string $username, string $category = 'billing'): array
    {
        if (blank($username)) {
            return [];
        }

        $user = User::query()->where('username', $username)->first(['email', 'preferences']);

        return $user ? $this->forUser($user, $category) : [];
    }

    /** @return array<int, int> */
    public function userIdsForUsername(?string $username, string $category = 'billing'): array
    {
        if (blank($username)) {
            return [];
        }

        $user = User::query()->where('username', $username)->first(['id', 'preferences']);

        return $user ? $this->userIdsForUser($user, $category) : [];
    }

    /** @return array<int, string> */
    public function forUser(User $user, string $category): array
    {
        if (! $this->preferences->allows($user, $category)) {
            return [];
        }
        $email = mb_strtolower(trim((string) $user->email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? [] : [$email];
    }

    /** @return array<int, int> */
    public function userIdsForUser(User $user, string $category): array
    {
        return $this->preferences->allowsInApp($user, $category) && $user->getKey()
            ? [(int) $user->getKey()]
            : [];
    }
}
