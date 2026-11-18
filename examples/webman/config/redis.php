<?php
declare(strict_types=1);

$prefix = getenv('STARTER_REDIS_PREFIX');
if ($prefix === false || $prefix === '') {
    return [];
}

$password = getenv('STARTER_REDIS_PASSWORD');

$connection = [
    'host' => getenv('STARTER_REDIS_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('STARTER_REDIS_PORT') ?: '6379'),
    'password' => $password === false || $password === '' ? null : $password,
    'database' => 0,
];

return [
    'default' => $connection + ['prefix' => $prefix],
    'starter_auth' => $connection,
];
