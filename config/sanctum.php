<?php

return [
    'stateful' => (function() {
        $env = env('SANCTUM_STATEFUL_DOMAINS', '');
        $domains = array_filter(array_map('trim', explode(',', $env)));
        $required = ['localhost', 'localhost:3000', '127.0.0.1', '127.0.0.1:3000', '127.0.0.1:8000', 'sikaa.online', 'www.sikaa.online', 'api.sikaa.online'];
        return array_values(array_unique(array_merge($domains, $required)));
    })(),

    'guard' => ['web'],

    'expiration' => null,

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
