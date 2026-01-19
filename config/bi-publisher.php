<?php

return [
    'environments' => [
        'dev2' => [
            'label' => env('BIP_DEV2_LABEL', 'DEV2'),
            'base_url' => env('BIP_DEV2_BASE_URL'),
            'username' => env('BIP_DEV2_USERNAME'),
            'password' => env('BIP_DEV2_PASSWORD'),
        ],
        'test' => [
            'label' => env('BIP_TEST_LABEL', 'TEST'),
            'base_url' => env('BIP_TEST_BASE_URL'),
            'username' => env('BIP_TEST_USERNAME'),
            'password' => env('BIP_TEST_PASSWORD'),
        ],
    ],
    'http' => [
        'timeout' => env('BIP_HTTP_TIMEOUT', 120),
        'connect_timeout' => env('BIP_HTTP_CONNECT_TIMEOUT', 10),
        'verify' => env('BIP_HTTP_VERIFY', true),
    ],
];
