<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class LegacyPasswordVerifier
{
    public function verify(string $plainText, string $storedHash): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/i', $storedHash) !== 1) {
            return false;
        }

        return hash_equals(strtolower($storedHash), hash('sha256', $plainText));
    }

    public function verifyAndUpgrade(User $user, string $plainText): bool
    {
        $storedHash = (string) $user->password_hash;

        if (str_starts_with($storedHash, '$2') || str_starts_with($storedHash, '$argon2')) {
            if (! password_verify($plainText, $storedHash)) {
                return false;
            }

            if (Hash::needsRehash($storedHash)) {
                $user->forceFill(['password_hash' => Hash::make($plainText)])->save();
            }

            return true;
        }

        if (! $this->verify($plainText, $storedHash)) {
            return false;
        }

        $user->forceFill(['password_hash' => Hash::make($plainText)])->save();

        return true;
    }
}
