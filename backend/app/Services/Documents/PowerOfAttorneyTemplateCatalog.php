<?php

namespace App\Services\Documents;

use Illuminate\Validation\ValidationException;

final class PowerOfAttorneyTemplateCatalog
{
    public const GENERAL = 'general-company';

    public const ANANDAMAHIDOL = 'anandamahidol';

    public const EGP = 'egp';

    public const GRANTEE_FORM = 'grantee-company-form';

    public const ANANDAMAHIDOL_EBIDDING = 'anandamahidol-ebidding';

    /** @return array<string, string> */
    public function options(): array
    {
        return [
            self::GENERAL => 'แบบทั่วไปบริษัท',
            self::ANANDAMAHIDOL => 'โรงพยาบาลอานันทมหิดล',
            self::EGP => 'ยื่น e-GP',
            self::GRANTEE_FORM => 'แบบระบุชื่อผู้รับมอบอำนาจ',
            self::ANANDAMAHIDOL_EBIDDING => 'รพ.อานันทมหิดล (e-bidding)',
        ];
    }

    public function normalize(mixed $template, string $hospitalName = ''): string
    {
        $value = trim((string) $template);
        if ($value === '') {
            return str_contains($hospitalName, 'อานันทมหิดล') ? self::ANANDAMAHIDOL : self::GENERAL;
        }
        if (! array_key_exists($value, $this->options())) {
            throw ValidationException::withMessages([
                'power_of_attorney.template_id' => 'ไม่พบรูปแบบหนังสือมอบอำนาจที่เลือก',
            ]);
        }

        return $value;
    }
}
