<?php

return [
    'paths' => ['api/*', 'sanctum/*', 'login', 'logout', '*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_unique(array_filter([
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'https://papayawhip-dotterel-898881.hostingersite.com',
        rtrim(env('FRONTEND_URL', ''), '/'),
    ]))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['*'],
    'max_age' => 0,
    'supports_credentials' => true,
];
