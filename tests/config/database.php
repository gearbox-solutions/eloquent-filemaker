<?php

return [
    'connections' => [
        'filemaker' => [
            'driver' => 'filemaker',
            'host' => env('DB_HOST', 'filemaker.test'),
            'database' => env('DB_DATABASE', 'tester'),
            'username' => env('DB_USERNAME', 'odatatester'),
            'password' => env('DB_PASSWORD', 'odatatester'),
            'prefix' => env('DB_PREFIX', ''),
            'protocol' => env('DB_PROTOCOL', 'https'),
        ],

        'filemaker2' => [
            'driver' => 'filemaker',
            'host' => env('DB_HOST', 'filemaker.test'),
            'database' => env('DB_DATABASE', 'tester2'),
            'username' => env('DB_USERNAME', 'odatatester2'),
            'password' => env('DB_PASSWORD', 'odatatester2'),
            'prefix' => env('DB_PREFIX', ''),
            'protocol' => env('DB_PROTOCOL', 'https'),
        ],

        'prefix' => [
            'driver' => 'filemaker',
            'host' => env('DB_HOST', 'filemaker.test'),
            'database' => env('DB_DATABASE', 'prefix'),
            'username' => env('DB_USERNAME', 'odatatester'),
            'password' => env('DB_PASSWORD', 'odatatester'),
            'prefix' => env('DB_PREFIX', 'odata-'),
            'protocol' => env('DB_PROTOCOL', 'https'),
        ],
    ],
];
