<?php

namespace Tests\Unit;

use App\Services\Authorization\RoleAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RoleAccessTest extends TestCase
{
    /** @return array<string, array{string|null, string, int}> */
    public static function roleProvider(): array
    {
        return [
            'super admin' => ['Super Admin', RoleAccess::GROUP_SUPER_ADMIN, 20],
            'thai super admin' => ['ผู้ดูแลระบบสูงสุด', RoleAccess::GROUP_SUPER_ADMIN, 20],
            'owner' => ['Owner', RoleAccess::GROUP_SUPER_ADMIN, 20],
            'sale' => ['Sale Executive', RoleAccess::GROUP_SALE, 7],
            'thai sale' => ['เซล', RoleAccess::GROUP_SALE, 7],
            'approver' => ['Approve Manager', RoleAccess::GROUP_APPROVE, 8],
            'thai approver' => ['หัวหน้าฝ่ายขาย', RoleAccess::GROUP_APPROVE, 8],
            'admin' => ['Admin', RoleAccess::GROUP_ADMIN, 8],
            'unknown' => ['Accounting', RoleAccess::GROUP_DEFAULT, 2],
            'empty' => [null, RoleAccess::GROUP_DEFAULT, 2],
        ];
    }

    #[Test]
    #[DataProvider('roleProvider')]
    public function it_matches_the_streamlit_role_menu(?string $role, string $expectedGroup, int $expectedMenuCount): void
    {
        $details = RoleAccess::detailsForRole($role);

        $this->assertSame($expectedGroup, $details['role_group']);
        $this->assertCount($expectedMenuCount, $details['menu']);
        $this->assertContains('notice_board', $details['abilities']);
    }

    #[Test]
    public function super_admin_menu_has_the_same_order_as_streamlit(): void
    {
        $labels = array_column(RoleAccess::detailsForRole('Super Admin')['menu'], 'label');

        $this->assertSame('สร้างใบเสนอราคา/เปิดบิลใหม่', $labels[0]);
        $this->assertSame('Call Sale Report', $labels[5]);
        $this->assertSame('รายการบิลรอพิมพ์', $labels[6]);
        $this->assertSame('แดชบอร์ดสถิติ', $labels[12]);
        $this->assertSame('บันทึกกิจกรรมระบบ', $labels[17]);
        $this->assertSame('วิเคราะห์งบประมาณ', $labels[18]);
        $this->assertSame('ตารางงาน', $labels[19]);
    }

    #[Test]
    public function audit_logs_are_available_only_to_super_admin(): void
    {
        $this->assertTrue(RoleAccess::can('Super Admin', 'audit_logs'));
        $this->assertTrue(RoleAccess::can('Owner', 'audit_logs'));
        $this->assertFalse(RoleAccess::can('Admin', 'audit_logs'));
        $this->assertFalse(RoleAccess::can('Approve Manager', 'audit_logs'));
        $this->assertFalse(RoleAccess::can('Sale Executive', 'audit_logs'));
    }

    #[Test]
    public function sales_call_report_is_available_to_sales_approvers_and_super_admin(): void
    {
        $this->assertTrue(RoleAccess::can('Sale Executive', 'sales_call_report'));
        $this->assertTrue(RoleAccess::can('Approve Manager', 'sales_call_report'));
        $this->assertTrue(RoleAccess::can('Super Admin', 'sales_call_report'));
        $this->assertFalse(RoleAccess::can('Admin', 'sales_call_report'));
    }

    #[Test]
    public function billing_visibility_is_owner_only_unless_the_role_is_privileged(): void
    {
        $this->assertTrue(RoleAccess::mustUseOwnBillingRecords('Sale Executive'));
        $this->assertTrue(RoleAccess::mustUseOwnBillingRecords('Accounting'));
        $this->assertFalse(RoleAccess::mustUseOwnBillingRecords('Admin'));
        $this->assertFalse(RoleAccess::mustUseOwnBillingRecords('Approve Manager'));
        $this->assertFalse(RoleAccess::mustUseOwnBillingRecords('Super Admin'));
        $this->assertTrue(RoleAccess::mustUseOwnBillingRecords('Super Admin', explicitMine: true));
    }

    #[Test]
    public function each_role_has_the_exact_streamlit_menu_order(): void
    {
        $this->assertSame(
            ['สร้างใบเสนอราคา/เปิดบิลใหม่', 'แก้ไขบิล/Draft(ใบเสนอราคา)', 'ใบเสนอราคา', 'ประวัติการเปิดบิลของฉัน', 'ติดตามอนุมัติวงเงิน', 'บันทึก Call Sale', 'ตารางงาน'],
            array_column(RoleAccess::detailsForRole('Sale')['menu'], 'label'),
        );
        $this->assertSame(
            ['แดชบอร์ดสถิติ', 'พิจารณาอนุมัติบิล', 'รายการที่อนุมัติแล้ว', 'รายการที่ไม่อนุมัติ', 'รายการบิลทั้งหมด', 'Call Sale Report', 'ตารางงาน', 'วิเคราะห์งบประมาณ'],
            array_column(RoleAccess::detailsForRole('Approve')['menu'], 'label'),
        );
        $this->assertSame(
            ['รายการบิลรอพิมพ์', 'จัดการระบบส่วนกลาง', 'จัดการข้อมูลหลัก', 'จัดการเครดิตลูกค้า', 'จัดการผู้ใช้งาน', 'ศูนย์จัดการเอกสาร', 'รายการบิลทั้งหมด', 'ตารางงาน'],
            array_column(RoleAccess::detailsForRole('Admin')['menu'], 'label'),
        );
    }
}
