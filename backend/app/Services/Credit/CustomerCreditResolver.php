<?php

namespace App\Services\Credit;

use App\Models\CreditProfile;

final class CustomerCreditResolver
{
    public function resolve(?string $arCode, ?string $arName): ?CreditProfile
    {
        $code = $this->normalizeCode($arCode);
        if ($code !== '') {
            $profile = CreditProfile::query()->where('ar_code', $code)->first();
            if ($profile) {
                return $profile;
            }
        }

        $name = $this->normalizeName($arName);
        if ($name === '') {
            return null;
        }

        $profile = CreditProfile::query()
            ->whereRaw("LOWER(REGEXP_REPLACE(TRIM(ar_name), '\\s+', ' ', 'g')) = ?", [$name])
            ->first();
        if ($profile) {
            return $profile;
        }

        return CreditProfile::query()
            ->where(fn ($query) => $query
                ->whereRaw("LOWER(ar_name) ILIKE ? ESCAPE '\\'", ['%'.$this->escapeLike($name).'%'])
                ->orWhereRaw("? ILIKE '%' || LOWER(TRIM(ar_name)) || '%'", [$name]))
            ->orderByRaw('LENGTH(ar_name)')
            ->first();
    }

    public function updateCurrentCredit(
        ?string $arCode,
        ?string $arName,
        float $creditLimit,
        int $creditTerm,
        float $creditOutstanding,
    ): ?CreditProfile {
        $profile = $this->resolve($arCode, $arName);
        $code = $this->normalizeCode($arCode);
        if (! $profile && $code === '') {
            return null;
        }

        $profile ??= new CreditProfile(['ar_code' => $code]);
        $profile->forceFill([
            'ar_name' => trim((string) $arName),
            'credit_limit' => max($creditLimit, 0),
            'credit_term' => max($creditTerm, 0),
            'credit_outstanding' => max($creditOutstanding, 0),
            'updated_at' => now(),
        ])->save();

        return $profile->fresh();
    }

    private function normalizeCode(?string $value): string
    {
        $code = trim((string) $value);

        return str_ends_with($code, '.0') ? substr($code, 0, -2) : $code;
    }

    private function normalizeName(?string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $value)));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
