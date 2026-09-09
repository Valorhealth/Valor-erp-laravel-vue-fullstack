<?php

namespace Tests\Unit;

use App\Models\User;
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
            'super admin' => ['Super Admin', RoleAccess::GROUP_SUPER_ADMIN, 21],
            'thai super admin' => ['ผู้ดูแลระบบสูงสุด', RoleAccess::GROUP_SUPER_ADMIN, 21],
            'owner' => ['Owner', RoleAccess::GROUP_SUPER_ADMIN, 21],
            'sale' => ['Sale Executive', RoleAccess::GROUP_SALE, 8],
            'thai sale' => ['เซล', RoleAccess::GROUP_SALE, 8],
            'approver' => ['Approve Manager', RoleAccess::GROUP_APPROVE, 10],
            'thai approver' => ['หัวหน้าฝ่ายขาย', RoleAccess::GROUP_APPROVE, 10],
            'admin' => ['Admin', RoleAccess::GROUP_ADMIN, 9],
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
        $this->assertSame('บันทึก Call Sale', $labels[5]);
        $this->assertSame('Call Sale Report', $labels[6]);
        $this->assertSame('จัดการระบบส่วนกลาง', $labels[7]);
        $this->assertSame('แดชบอร์ดสถิติ', $labels[12]);
        $this->assertSame('บันทึกกิจกรรมระบบ', $labels[17]);
        $this->assertSame('วิเคราะห์งบประมาณ', $labels[18]);
        $this->assertSame('ตารางงาน', $labels[19]);
        $this->assertSame('ตารางจองเครื่อง FibroScan', $labels[20]);
    }

    #[Test]
    public function approver_can_read_document_center_without_admin_write_access(): void
    {
        $this->assertTrue(RoleAccess::can('Approve', 'document_center'));
        $this->assertFalse(RoleAccess::can('Approve', 'system_admin'));
        $this->assertFalse(RoleAccess::can('Approve', 'edit_bill'));
        $this->assertFalse(RoleAccess::can('Sale', 'document_center'));
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
    public function sales_call_entry_and_report_are_separate_permissions(): void
    {
        $this->assertTrue(RoleAccess::can('Sale Executive', 'sales_call_entry'));
        $this->assertFalse(RoleAccess::can('Sale Executive', 'sales_call_report'));
        $this->assertFalse(RoleAccess::can('Approve Manager', 'sales_call_entry'));
        $this->assertTrue(RoleAccess::can('Approve Manager', 'sales_call_report'));
        $this->assertTrue(RoleAccess::can('Super Admin', 'sales_call_entry'));
        $this->assertTrue(RoleAccess::can('Super Admin', 'sales_call_report'));
        $this->assertTrue(RoleAccess::can('Admin', 'sales_call_report'));
    }

    #[Test]
    public function equipment_booking_is_shared_by_all_operational_roles(): void
    {
        $this->assertTrue(RoleAccess::can('Sale Executive', 'equipment_booking'));
        $this->assertTrue(RoleAccess::can('Approve Manager', 'equipment_booking'));
        $this->assertTrue(RoleAccess::can('Admin', 'equipment_booking'));
        $this->assertTrue(RoleAccess::can('Super Admin', 'equipment_booking'));
        $this->assertFalse(RoleAccess::can('Accounting', 'equipment_booking'));
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
    public function per_user_overrides_can_grant_and_deny_both_menu_and_api_abilities(): void
    {
        $user = new User;
        $user->forceFill([
            'role' => 'Admin (สั่งพิมพ์ใบเปิดบิล)',
            'permission_overrides' => ['budget_analysis' => true, 'calendar' => false],
        ]);

        $details = RoleAccess::detailsForUser($user);
        $menuKeys = array_column($details['menu'], 'key');

        $this->assertContains('budget_analysis', $details['abilities']);
        $this->assertContains('budget_analysis', $menuKeys);
        $this->assertNotContains('calendar', $details['abilities']);
        $this->assertNotContains('calendar', $menuKeys);
        $this->assertTrue(RoleAccess::canUser($user, 'budget_analysis'));
        $this->assertFalse(RoleAccess::canUser($user, 'calendar'));
    }

    #[Test]
    public function super_admin_always_keeps_full_access(): void
    {
        $user = new User;
        $user->forceFill([
            'role' => 'Super Admin (ผู้ดูแลระบบสูงสุด)',
            'permission_overrides' => ['audit_logs' => false, 'user_management' => false],
        ]);

        $this->assertTrue(RoleAccess::canUser($user, 'audit_logs', 'user_management'));
        $this->assertContains('audit_logs', array_column(RoleAccess::detailsForUser($user)['menu'], 'key'));
    }

    #[Test]
    public function permission_catalog_lists_both_sales_call_pages_for_every_target_role(): void
    {
        $catalog = collect(RoleAccess::permissionCatalog());
        $entry = $catalog->firstWhere('key', 'sales_call_entry');
        $report = $catalog->firstWhere('key', 'sales_call_report');

        $this->assertSame('บันทึก Call Sale', $entry['label']);
        $this->assertSame('☎️', $entry['icon']);
        $this->assertSame('Call Sale Report', $report['label']);
        $this->assertSame('📈', $report['icon']);
    }

    #[Test]
    public function each_role_has_the_exact_streamlit_menu_order(): void
    {
        $this->assertSame(
            ['สร้างใบเสนอราคา/เปิดบิลใหม่', 'แก้ไขบิล/Draft(ใบเสนอราคา)', 'ศูนย์จัดการเอกสาร', 'ประวัติการเปิดบิลของฉัน', 'ติดตามอนุมัติวงเงิน', 'บันทึก Call Sale', 'ตารางงาน', 'ตารางจองเครื่อง FibroScan'],
            array_column(RoleAccess::detailsForRole('Sale')['menu'], 'label'),
        );
        $this->assertSame(
            ['แดชบอร์ดสถิติ', 'พิจารณาอนุมัติบิล', 'รายการที่อนุมัติแล้ว', 'รายการที่ไม่อนุมัติ', 'รายการบิลทั้งหมด', 'Call Sale Report', 'ตารางงาน', 'ตารางจองเครื่อง FibroScan', 'วิเคราะห์งบประมาณ', 'ศูนย์จัดการเอกสาร'],
            array_column(RoleAccess::detailsForRole('Approve')['menu'], 'label'),
        );
        $this->assertSame(
            ['จัดการระบบส่วนกลาง', 'จัดการข้อมูลหลัก', 'จัดการเครดิตลูกค้า', 'จัดการผู้ใช้งาน', 'ศูนย์จัดการเอกสาร', 'รายการบิลทั้งหมด', 'ตารางงาน', 'ตารางจองเครื่อง FibroScan', 'Call Sale Report'],
            array_column(RoleAccess::detailsForRole('Admin')['menu'], 'label'),
        );
    }
}
