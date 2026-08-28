<?php

return [
    'expires_minutes' => (int) env('PASSWORD_RESET_EXPIRES_MINUTES', 30),
    'frontend_url' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://127.0.0.1:5173')), '/'),
];
