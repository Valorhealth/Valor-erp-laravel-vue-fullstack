<?php

namespace App\Services\Reference;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class ThaiLocationService
{
    /** @return array<string, array<string, array<string, string>>> */
    public function all(): array
    {
        return Cache::rememberForever('thai-locations-v1', function (): array {
            $path = resource_path('data/thai_locations.json');
            if (! is_file($path)) {
                throw new RuntimeException('ไม่พบไฟล์ข้อมูลที่อยู่ประเทศไทย');
            }

            $rows = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $locations = [];
            foreach ($rows as $row) {
                $province = trim((string) ($row['province'] ?? ''));
                $district = trim((string) ($row['amphoe'] ?? ''));
                $subDistrict = trim((string) ($row['district'] ?? ''));
                $zipcode = trim(explode(',', (string) ($row['zipcode'] ?? ''))[0]);
                if ($province !== '' && $district !== '' && $subDistrict !== '') {
                    $locations[$province][$district][$subDistrict] = $zipcode;
                }
            }

            return $locations;
        });
    }
}
