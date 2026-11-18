<?php
declare(strict_types=1);

$database = getenv('STARTER_DB_DATABASE');
$username = getenv('STARTER_DB_USERNAME');
if ($database === false || $database === '' || $username === false || $username === '') {
    return [];
}

$password = getenv('STARTER_DB_PASSWORD');

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => getenv('STARTER_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('STARTER_DB_PORT') ?: '3306'),
            'database' => $database,
            'username' => $username,
            'password' => $password === false ? '' : $password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => true,
        ],
    ],
];
