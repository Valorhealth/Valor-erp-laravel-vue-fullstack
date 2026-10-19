<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class UserIdentityUpdater
{
    /** @param array<string, mixed> $attributes */
    public function update(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes): User {
            $oldUsername = (string) $user->username;
            $user->forceFill($attributes)->save();
            if ($oldUsername !== $user->username) {
                $this->propagateUsername($user, $oldUsername);
            }

            return $user->fresh();
        });
    }

    private function propagateUsername(User $user, string $oldUsername): void
    {
        $this->updateByUserId('billing_records', 'creator_user_id', 'created_by', $user);
        $this->updateByUserId('quotations', 'creator_user_id', 'created_by', $user);
        $this->updateByOldUsername('credit_requests', 'created_by', $oldUsername, $user->username);
        $this->updateByOldUsername('notice_board', 'created_by', $oldUsername, $user->username);
        $this->updateByUserId('fibroscan_bookings', 'booked_by_user_id', 'booked_by', $user);
        $this->updateByUserId('sales_call_activities', 'seller_user_id', 'seller_username', $user);
        $this->updateByUserId('sales_call_import_batches', 'seller_user_id', 'seller_username', $user);
        $this->updateByUserId('quotation_document_drafts', 'updated_by_user_id', 'updated_by', $user);
        $this->updateByUserId('quotation_document_help_requests', 'requested_by_user_id', 'requested_by', $user);
        $this->updateByUserId('quotation_document_help_requests', 'assigned_to_user_id', 'assigned_to', $user);
        $this->updateTaskUsername('assigned_to_user_id', 'assigned_to', $user, $oldUsername);
        $this->updateTaskUsername('assigned_by_user_id', 'assigned_by', $user, $oldUsername);
    }

    private function updateByUserId(string $table, string $userIdColumn, string $usernameColumn, User $user): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumns($table, [$userIdColumn, $usernameColumn])) {
            return;
        }
        DB::table($table)->where($userIdColumn, $user->id)->update([$usernameColumn => $user->username]);
    }

    private function updateByOldUsername(string $table, string $column, string $oldUsername, string $newUsername): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        DB::table($table)->where($column, $oldUsername)->update([$column => $newUsername]);
    }

    private function updateTaskUsername(string $userIdColumn, string $usernameColumn, User $user, string $oldUsername): void
    {
        if (! Schema::hasTable('sales_tasks') || ! Schema::hasColumns('sales_tasks', [$userIdColumn, $usernameColumn])) {
            return;
        }
        DB::table('sales_tasks')
            ->where(fn ($query) => $query->where($userIdColumn, $user->id)
                ->orWhere(fn ($legacy) => $legacy->whereNull($userIdColumn)->where($usernameColumn, $oldUsername)))
            ->update([$usernameColumn => $user->username]);
    }
}
