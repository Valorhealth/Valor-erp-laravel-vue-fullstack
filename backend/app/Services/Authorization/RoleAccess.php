<?php

namespace App\Services\Authorization;

final class RoleAccess
{
    public const GROUP_SUPER_ADMIN = 'super_admin';

    public const GROUP_SALE = 'sale';

    public const GROUP_APPROVE = 'approve';

    public const GROUP_ADMIN = 'admin';

    public const GROUP_DEFAULT = 'default';

    /** @var array<string, array<int, array{key: string, label: string, path: string, icon: string}>> */
    private const MENUS = [
        self::GROUP_SALE => [
            ['key' => 'create_bill', 'label' => 'สร้างใบเสนอราคา/เปิดบิลใหม่', 'path' => '/bills/new', 'icon' => '🌼'],
            ['key' => 'edit_bill', 'label' => 'แก้ไขบิล/Draft(ใบเสนอราคา)', 'path' => '/bills/edit', 'icon' => '🖊️'],
            ['key' => 'sales_documents', 'label' => 'ใบเสนอราคา', 'path' => '/sales-documents', 'icon' => '🧾'],
            ['key' => 'my_billing_history', 'label' => 'ประวัติการเปิดบิลของฉัน', 'path' => '/billing-records/my', 'icon' => '◷'],
            ['key' => 'credit_request_status', 'label' => 'ติดตามอนุมัติวงเงิน', 'path' => '/credit-requests', 'icon' => '💵'],
            ['key' => 'sales_call_report', 'label' => 'บันทึก Call Sale', 'path' => '/sales-call-report', 'icon' => '☎️'],
            ['key' => 'calendar', 'label' => 'ตารางงาน', 'path' => '/calendar', 'icon' => '📆'],
        ],
        self::GROUP_APPROVE => [
            ['key' => 'dashboard_stats', 'label' => 'แดชบอร์ดสถิติ', 'path' => '/dashboard', 'icon' => '📊'],
            ['key' => 'approve_bills', 'label' => 'พิจารณาอนุมัติบิล', 'path' => '/billing-approvals', 'icon' => '💠'],
            ['key' => 'approved_bills', 'label' => 'รายการที่อนุมัติแล้ว', 'path' => '/billing-records/approved', 'icon' => '✅'],
            ['key' => 'rejected_bills', 'label' => 'รายการที่ไม่อนุมัติ', 'path' => '/billing-records/rejected', 'icon' => '❌'],
            ['key' => 'all_bills', 'label' => 'รายการบิลทั้งหมด', 'path' => '/billing-records/all', 'icon' => '▤'],
            ['key' => 'sales_call_report', 'label' => 'Call Sale Report', 'path' => '/sales-call-report', 'icon' => '📈'],
            ['key' => 'calendar', 'label' => 'ตารางงาน', 'path' => '/calendar', 'icon' => '📆'],
            ['key' => 'budget_analysis', 'label' => 'วิเคราะห์งบประมาณ', 'path' => '/budget-analysis', 'icon' => '💱'],
        ],
        self::GROUP_ADMIN => [
            ['key' => 'print_queue', 'label' => 'รายการบิลรอพิมพ์', 'path' => '/print-queue', 'icon' => '⌛'],
            ['key' => 'system_admin', 'label' => 'จัดการระบบส่วนกลาง', 'path' => '/system-admin', 'icon' => '🗂️'],
            ['key' => 'master_data', 'label' => 'จัดการข้อมูลหลัก', 'path' => '/master-data', 'icon' => '💼'],
            ['key' => 'credit_profiles', 'label' => 'จัดการเครดิตลูกค้า', 'path' => '/credit-profiles', 'icon' => '💼'],
            ['key' => 'user_management', 'label' => 'จัดการผู้ใช้งาน', 'path' => '/users', 'icon' => '🤵'],
            ['key' => 'document_center', 'label' => 'ศูนย์จัดการเอกสาร', 'path' => '/document-center', 'icon' => '🧾'],
            ['key' => 'all_bills', 'label' => 'รายการบิลทั้งหมด', 'path' => '/billing-records/all', 'icon' => '▤'],
            ['key' => 'calendar', 'label' => 'ตารางงาน', 'path' => '/calendar', 'icon' => '📆'],
        ],
        self::GROUP_DEFAULT => [
            ['key' => 'create_bill', 'label' => 'เปิดบิลใหม่', 'path' => '/bills/new', 'icon' => '🌼'],
            ['key' => 'my_billing_history', 'label' => 'ประวัติการเปิดบิลของฉัน', 'path' => '/billing-records/my', 'icon' => '◷'],
        ],
    ];

    /** @return array{role_group: string, abilities: array<int, string>, menu: array<int, array{key: string, label: string, path: string, icon: string}>} */
    public static function detailsForRole(?string $role): array
    {
        $group = self::groupForRole($role);
        $menu = self::menuForGroup($group);

        return [
            'role_group' => $group,
            'abilities' => array_values(array_unique([
                'notice_board',
                ...array_column($menu, 'key'),
            ])),
            'menu' => $menu,
        ];
    }

    public static function groupForRole(?string $role): string
    {
        $normalized = mb_strtolower(trim((string) $role));

        if (self::containsAny($normalized, ['super admin', 'แอดมินใหญ่', 'ผู้ดูแลระบบสูงสุด', 'owner'])) {
            return self::GROUP_SUPER_ADMIN;
        }

        if (self::containsAny($normalized, ['sale', 'เซล'])) {
            return self::GROUP_SALE;
        }

        if (self::containsAny($normalized, ['approve', 'หัวหน้า', 'อนุมัติ'])) {
            return self::GROUP_APPROVE;
        }

        if (self::containsAny($normalized, ['admin', 'แอดมิน'])) {
            return self::GROUP_ADMIN;
        }

        return self::GROUP_DEFAULT;
    }

    public static function can(?string $role, string ...$abilities): bool
    {
        $allowed = self::detailsForRole($role)['abilities'];

        return count(array_intersect($allowed, $abilities)) > 0;
    }

    /** @return array<int, array{key: string, label: string, path: string, icon: string}> */
    private static function menuForGroup(string $group): array
    {
        if ($group !== self::GROUP_SUPER_ADMIN) {
            return self::MENUS[$group];
        }

        $sale = self::MENUS[self::GROUP_SALE];
        $admin = self::MENUS[self::GROUP_ADMIN];
        $approve = self::MENUS[self::GROUP_APPROVE];

        return [
            ...array_slice($sale, 0, 5),
            $approve[5],
            ...array_slice($admin, 0, 6),
            ...array_slice($approve, 0, 5),
            ['key' => 'audit_logs', 'label' => 'บันทึกกิจกรรมระบบ', 'path' => '/audit-logs', 'icon' => '▤'],
            $approve[7],
            $admin[7],
        ];
    }

    public static function mustUseOwnBillingRecords(?string $role, bool $explicitMine = false): bool
    {
        if ($explicitMine) {
            return true;
        }

        return ! in_array(self::groupForRole($role), [
            self::GROUP_SUPER_ADMIN,
            self::GROUP_APPROVE,
            self::GROUP_ADMIN,
        ], true);
    }

    /** @param array<int, string> $needles */
    private static function containsAny(string $role, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($role, $needle)) {
                return true;
            }
        }

        return false;
    }
}
