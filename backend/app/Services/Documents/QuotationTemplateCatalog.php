<?php

namespace App\Services\Documents;

use Illuminate\Validation\ValidationException;

final class QuotationTemplateCatalog
{
    public function exampleOriginDetails(string $template, string $sku): string
    {
        return match ($template) {
            self::LUANGPHO_THAWEESAK => "UNSPSC: 42.23.16.09\nชื่อผู้ผลิต AVENT S.DE.R.L. DE C.V.\nประเทศที่ผลิต United Mexican states.\nไม่มีผลิตในประเทศไทย\nไม่มี มอก. บริษัทเป็นผู้แทนจำหน่ายแต่เพียงผู้เดียว",
            self::CHAROENKRUNG_PRACHARAK => "ชื่อผู้ผลิต AVENT S.DE.R.L. DE C.V.\nประเทศที่ผลิต United Mexican states.\nไม่มีผลิตในประเทศไทย\nไม่มี มอก. บริษัทเป็นผู้แทนจำหน่ายแต่เพียงผู้เดียว",
            default => '',
        };
    }

    public const STANDARD = 'standard';

    public const SIRIRAJ = 'siriraj';

    public const LUANGPHO_THAWEESAK = 'luangpho-thaweesak';

    public const POLICE_GENERAL = 'police-general';

    public const CHAROENKRUNG_PRACHARAK = 'charoenkrung-pracharak';

    public const RAMATHIBODI = 'ramathibodi';

    public const SIRIRAJ_PIYAMAHARAJKARUN = 'siriraj-piyamaharajkarun';

    /**
     * @return array<string, array{
     *     name: string,
     *     description: string,
     *     recipient: string,
     *     subject: string,
     *     intro: string,
     *     columns: array<int, string>,
     *     summary: string,
     *     terms: array<int, string>
     * }>
     */
    public function hospitalProfiles(): array
    {
        return [
            self::SIRIRAJ => [
                'name' => 'รพ.ศิริราช',
                'description' => 'แบบใบเสนอราคาคาดการณ์ทั้งปี พร้อมรหัสเวชภัณฑ์และยอดรวม',
                'recipient' => 'คณบดี คณะแพทยศาสตร์ศิริราชพยาบาล',
                'subject' => 'ใบเสนอราคาเวชภัณฑ์',
                'intro' => 'บริษัท เวเลอร์ เฮลธ์ จำกัด มีความยินดีขอเสนอราคาเวชภัณฑ์ ดังต่อไปนี้',
                'columns' => ['sequence', 'sku', 'description', 'pack', 'unit_price_vat'],
                'summary' => 'vat',
                'terms' => [
                    'กำหนดยืนราคา ถึง 30 กันยายน 2570',
                    'กำหนดส่งของ ภายใน 5 วัน หรือตามที่คณะฯ กำหนด หลังจากได้รับใบสั่งซื้อ ตามสัญญาจะซื้อจะขาย แบบราคาคงที่ไม่จำกัดปริมาณ',
                    'เวชภัณฑ์ดังกล่าว เป็นผลิตภัณฑ์จากประเทศ China',
                    'บริษัท เวเลอร์ เฮลธ์ จำกัด เป็นผู้แทนจำหน่ายแต่เพียงผู้เดียวในประเทศไทย',
                ],
            ],
            self::LUANGPHO_THAWEESAK => [
                'name' => 'รพ.หลวงพ่อทวีศักดิ์',
                'description' => 'แบบรายการสองภาษาและสรุป Gross / Discount / Net / VAT / Total',
                'recipient' => 'หัวหน้าเจ้าหน้าที่กลุ่มงานเภสัชกรรม โรงพยาบาลหลวงพ่อทวีศักดิ์',
                'subject' => '',
                'intro' => 'บริษัท เวเลอร์ เฮลธ์ จำกัด มีความยินดีขอเสนอราคาผลิตภัณฑ์ ดังรายการต่อไปนี้',
                'columns' => ['sku_bilingual', 'description_bilingual', 'unit_bilingual', 'quantity_bilingual', 'unit_price_bilingual', 'amount_bilingual'],
                'summary' => 'gross_discount_vat',
                'terms' => [
                    'ยืนยันราคาภายใน 60 วัน',
                    'กำหนดส่งของภายใน 5 วัน นับถัดจากวันที่ได้รับใบสั่งซื้อ',
                    'เจ้าหน้าที่ได้ต่อรองราคาแล้ว บริษัทฯ ไม่สามารถลดลงได้อีกเพราะราคาที่เสนอมาเป็นราคาที่ต่ำสุดแล้ว',
                ],
            ],
            self::POLICE_GENERAL => [
                'name' => 'รพ.ตำรวจ',
                'description' => 'แบบหนังสือถึงนายแพทย์ใหญ่ พร้อมราคาและยอดรวมภาษี',
                'recipient' => 'นายแพทย์ใหญ่ (สบ 8) โรงพยาบาลตำรวจ',
                'subject' => 'ขอเสนอราคาวัสดุอุปกรณ์ทางการแพทย์',
                'intro' => 'บริษัท เวเลอร์ เฮลธ์ จำกัด มีความยินดีเสนอราคาผลิตภัณฑ์ ดังรายการต่อไปนี้',
                'columns' => ['sequence', 'sku', 'description', 'quantity', 'unit', 'unit_price_vat', 'amount'],
                'summary' => 'vat',
                'terms' => [
                    'กำหนดยืนยันราคา 60 วัน',
                    'กำหนดส่งของภายใน 10 วัน นับถัดจากวันที่ได้รับใบสั่งซื้อ',
                    'ผลิตภัณฑ์ AVANOS ประเทศสหรัฐอเมริกา',
                    'เจ้าหน้าที่ของโรงพยาบาลได้ต่อรองราคาแล้ว แต่ทางบริษัทไม่สามารถลดลงได้อีก เนื่องจากเสนอราคาสุทธิที่ต่ำสุดแล้ว และราคาที่เสนอนี้เป็นราคาที่ไม่สูงไปกว่าราคาอ้างอิง ที่จำหน่ายให้กับสถานพยาบาลอื่น',
                ],
            ],
            self::CHAROENKRUNG_PRACHARAK => [
                'name' => 'เจริญกรุงประชารักษ์',
                'description' => 'แบบรายการครุภัณฑ์ พร้อมหน่วยนับ ราคา และหมายเหตุการส่งมอบ',
                'recipient' => 'ผู้อำนวยการ โรงพยาบาลเจริญกรุงประชารักษ์',
                'subject' => '',
                'intro' => 'บริษัท เวเลอร์ เฮลธ์ จำกัด มีความยินดีขอเสนอราคาผลิตภัณฑ์ ดังรายการต่อไปนี้',
                'columns' => ['sequence', 'sku', 'description', 'quantity', 'unit', 'unit_price_vat'],
                'summary' => 'vat',
                'terms' => [
                    'กำหนดยืนราคา 360 วัน',
                    'กำหนดส่งมอบสินค้า 5 วัน นับถัดจากวันลงนามในใบสั่งซื้อ',
                    'ตามที่เจ้าหน้าที่ได้ต่อรองราคาตกลงนั้น บริษัทฯไม่สามารถลดลงได้อีก เนื่องจากเป็นราคาต่ำสุด',
                    'บริษัท เวเลอร์ เฮลธ์ จำกัด ธนาคารไทยพาณิชย์ สาขาถนนสาทร เลขที่ 101-242025-0',
                ],
            ],
            self::RAMATHIBODI => [
                'name' => 'รพ.รามา',
                'description' => 'แบบยืนยันราคาเวชภัณฑ์ รายการราคาต่อหน่วยรวม VAT',
                'recipient' => 'ผู้อำนวยการ โรงพยาบาลรามาธิบดี',
                'subject' => 'ขอเสนอราคาเวชภัณฑ์ทางการแพทย์',
                'intro' => 'บริษัท เวเลอร์ เฮลธ์ จำกัด มีความยินดีขอเสนอราคาดังรายการต่อไปนี้',
                'columns' => ['sequence', 'sku', 'description', 'unit', 'unit_price_vat', 'remark'],
                'summary' => 'none',
                'terms' => [
                    'ราคาที่เสนอนี้รวมภาษีมูลค่าเพิ่ม 7% แล้ว',
                    'ผลิตภัณฑ์จากประเทศ USA',
                    'กำหนดส่งของ 5 วันทำการ',
                    'รับประกันคุณภาพ 1 ปี',
                    'กำหนดยืนราคาไม่น้อยกว่า 1 ปี',
                    'สินค้ามีวันหมดอายุข้างกล่อง',
                    'สินค้าเปลี่ยนแปลงรายการชื่อเวชภัณฑ์ จาก Kimberly เป็น Avanos',
                ],
            ],
            self::SIRIRAJ_PIYAMAHARAJKARUN => [
                'name' => 'รพ.ศิริราชปิย',
                'description' => 'แบบรายการเวชภัณฑ์ รหัส SAP หน่วยสั่งซื้อ ขนาดบรรจุ และยอดรวม',
                'recipient' => 'ประธานคณะกรรมการจัดหา โรงพยาบาลศิริราช ปิยมหาราชการุณย์',
                'subject' => 'ขอเสนอราคาเวชภัณฑ์',
                'intro' => '',
                'columns' => ['sequence', 'sku', 'description', 'quantity', 'unit', 'pack', 'unit_price_vat', 'amount'],
                'summary' => 'vat',
                'terms' => [
                    'กำหนดยืนยันราคา วันที่ 1 ตุลาคม 2569 ถึง 30 กันยายน 2570',
                    'กำหนดส่งของภายใน 5 วันทำการ นับจากวันที่ได้รับใบสั่งซื้อ',
                    'ราคาที่ทำการซื้อขายต้องเป็นราคาเดียวกับโรงพยาบาลศิริราช โดยหากทางโรงพยาบาลศิริราช ปิยมหาราชการุณย์ตรวจพบว่ามีราคาต่างกัน บริษัทจะเสียค่าปรับเท่ากับมูลค่าที่แตกต่างกัน ซึ่งมีผลย้อนหลังไปถึงวันที่ผู้จะขายส่งมอบสินค้าที่มีราคาไม่เท่ากันให้แก่โรงพยาบาลฯ',
                    'เงื่อนไขในการแลกเปลี่ยนสินค้าก่อนหมดอายุ.....1....ปี',
                    'ยอดขั้นต่ำในการเปิดบิล 1,000 บาท',
                ],
            ],
        ];
    }

    /** @return array<int, array{id: string, name: string, description: string, available: bool}> */
    public function options(): array
    {
        $options = [
            [
                'id' => self::STANDARD,
                'name' => 'ใบเสนอราคามาตรฐาน',
                'description' => 'รูปแบบเอกสารที่ระบบใช้อยู่ในปัจจุบัน',
                'available' => true,
            ],
        ];

        foreach ($this->hospitalProfiles() as $id => $profile) {
            $options[] = [
                'id' => $id,
                'name' => $profile['name'],
                'description' => $profile['description'],
                'available' => true,
            ];
        }

        return $options;
    }

    public function requireAvailable(?string $templateId): string
    {
        $selected = $templateId ?: self::STANDARD;
        if ($selected !== self::STANDARD && ! array_key_exists($selected, $this->hospitalProfiles())) {
            throw ValidationException::withMessages([
                'template' => 'ไม่พบรูปแบบใบเสนอราคาที่เลือก กรุณาเลือกรูปแบบจากรายการ',
            ]);
        }

        return $selected;
    }

    /** @return array{name: string, description: string, recipient: string, subject: string, intro: string, columns: array<int, string>, summary: string, terms: array<int, string>} */
    public function profile(string $templateId): array
    {
        $selected = $this->requireAvailable($templateId);
        if ($selected === self::STANDARD) {
            throw ValidationException::withMessages([
                'template' => 'ใบเสนอราคามาตรฐานไม่มี Hospital Profile',
            ]);
        }

        return $this->hospitalProfiles()[$selected];
    }
}
