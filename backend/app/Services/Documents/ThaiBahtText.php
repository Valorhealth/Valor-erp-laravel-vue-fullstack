<?php

namespace App\Services\Documents;

final class ThaiBahtText
{
    private const DIGITS = ['', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];

    private const UNITS = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];

    public function convert(float $amount): string
    {
        $rounded = number_format($amount, 2, '.', '');
        [$baht, $satang] = array_map('intval', explode('.', $rounded));
        $result = $this->readInteger($baht).'บาท';

        return $satang === 0 ? $result.'ถ้วน' : $result.$this->readInteger($satang).'สตางค์';
    }

    private function readInteger(int $number): string
    {
        if ($number === 0) {
            return 'ศูนย์';
        }
        if ($number >= 1_000_000) {
            $millions = intdiv($number, 1_000_000);
            $remainder = $number % 1_000_000;

            return $this->readInteger($millions).'ล้าน'.($remainder ? $this->readInteger($remainder) : '');
        }

        $digits = str_split((string) $number);
        $length = count($digits);
        $text = '';
        foreach ($digits as $index => $digitText) {
            $digit = (int) $digitText;
            if ($digit === 0) {
                continue;
            }
            $position = $length - $index - 1;
            if ($position === 1 && $digit === 1) {
                $word = '';
            } elseif ($position === 1 && $digit === 2) {
                $word = 'ยี่';
            } elseif ($position === 0 && $digit === 1 && $length > 1) {
                $word = 'เอ็ด';
            } else {
                $word = self::DIGITS[$digit];
            }
            $text .= $word.self::UNITS[$position];
        }

        return $text;
    }
}
