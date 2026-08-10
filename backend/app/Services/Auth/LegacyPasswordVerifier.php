<?php

namespace App\Services\Auth;

final class LegacyPasswordVerifier
{
    public function verify(string $plainText, string $storedHash): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/i', $storedHash) !== 1) {
            return false;
        }

        return hash_equals(strtolower($storedHash), hash('sha256', $plainText));
    }
}
