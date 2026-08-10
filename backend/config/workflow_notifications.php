<?php

return [
    'enabled' => env('ERP_EMAIL_NOTIFICATIONS_ENABLED', true),

    // เพิ่ม 'super_admin' ในกลุ่มที่ต้องการ หากต้องการให้ Super Admin รับแจ้งเตือนด้วย
    'recipient_groups' => [
        'approve' => ['approve'],
        'admin' => ['admin'],
        // 'approve' => ['approve', 'super_admin'],
        // 'admin' => ['admin', 'super_admin'],
    ],
];
