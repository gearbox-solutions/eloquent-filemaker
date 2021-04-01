<?php

return [
    'connections' => [
        'filemaker' => [
            'driver' => 'filemaker',
            'host' => env('DB_HOST', 'filemaker.test'),
            'database' => env('DB_DATABASE', 'tester'),
            'username' => env('DB_USERNAME', 'dapitester'),
            'password' => env('DB_PASSWORD', 'dapitester'),
            'prefix' => env('DB_PREFIX', ''),
            'version' => env('DB_VERSION', 'vLatest'),
            'protocol' => env('DB_PROTOCOL', 'https'),
        ],

        'filemaker2' => [
            'driver' => 'filemaker',
            'host' => env('DB_HOST', 'filemaker.test'),
            'database' => env('DB_DATABASE', 'tester2'),
            'username' => env('DB_USERNAME', 'dapitester2'),
            'password' => env('DB_PASSWORD', 'dapitester2'),
            'prefix' => env('DB_PREFIX', ''),
            'version' => env('DB_VERSION', 'vLatest'),
            'protocol' => env('DB_PROTOCOL', 'https'),
        ],

        'prefix' => [
            'driver' => 'filemaker',
            'host' => env('DB_HOST', 'filemaker.test'),
            'database' => env('DB_DATABASE', 'prefix'),
            'username' => env('DB_USERNAME', 'dapitester'),
            'password' => env('DB_PASSWORD', 'dapitester'),
            'prefix' => env('DB_PREFIX', 'dapi-'),
            'version' => env('DB_VERSION', 'vLatest'),
            'protocol' => env('DB_PROTOCOL', 'https'),
        ],
    ],
];
