<?php

declare(strict_types=1);

use Z0x\WebmanStarter\saas\infrastructure\StarterDatabaseConfig;

$connection = StarterDatabaseConfig::fromEnvironment();

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'starter_phinxlog',
        'default_environment' => 'starter',
        'starter' => [
            'adapter' => 'mysql',
            'host' => $connection['host'],
            'port' => $connection['port'],
            'name' => $connection['database'],
            'user' => $connection['username'],
            'pass' => $connection['password'],
            'charset' => $connection['charset'],
            'collation' => $connection['collation'],
        ],
    ],
    'version_order' => 'creation',
];
