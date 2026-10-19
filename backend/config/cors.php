<?php

$isProduction = env('APP_ENV', 'production') === 'production';
$origins = [env('FRONTEND_URL', $isProduction ? env('APP_URL') : 'http://127.0.0.1:5173')];
if (! $isProduction) {
    $origins[] = 'http://localhost:5173';
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_unique($origins))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 0,
    'supports_credentials' => true,
];
