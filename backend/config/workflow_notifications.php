<?php

return [
    'enabled' => env('ERP_EMAIL_NOTIFICATIONS_ENABLED', true),
    'transport' => env('WORKFLOW_EMAIL_TRANSPORT', 'smtp'),

    'gmail' => [
        'client_id' => env('GMAIL_CLIENT_ID'),
        'client_secret' => env('GMAIL_CLIENT_SECRET'),
        'refresh_token' => env('GMAIL_REFRESH_TOKEN'),
        'sender' => env('GMAIL_SENDER', 'sukirin.valorhealth@gmail.com'),
    ],

    // เพิ่ม 'super_admin' ในกลุ่มที่ต้องการ หากต้องการให้ Super Admin รับแจ้งเตือนด้วย
    'recipient_groups' => [
        'approve' => ['approve'],
        'admin' => ['admin'],
        // 'approve' => ['approve', 'super_admin'],
        // 'admin' => ['admin', 'super_admin'],
    ],
];
