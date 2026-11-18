<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
    // Load the host's database settings without starting its HTTP services or provider configuration.
    Dotenv::createUnsafeMutable($root)->safeLoad();
    $database = require $root . '/config/database.php';
    if (($database['default'] ?? null) !== 'mysql') {
        fwrite(STDERR, "Configure the example host's default mysql database before setup.\n");
        exit(1);
    }

    $databases = new Manager();
    $databases->addConnection($database['connections']['mysql'], 'mysql');
    $connection = $databases->getConnection('mysql');
    if (!$connection->getSchemaBuilder()->hasTable('starter_packages')) {
        fwrite(STDERR, "Run the starter migrations before setting up the example.\n");
        exit(1);
    }

    // Package names are not unique in the starter schema. Serialize setup runs per database.
    $lock = hash('sha256', 'webman-starter:example-setup:' . $connection->getDatabaseName());
    $acquired = $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lock], false);
    if ((int) $acquired->acquired !== 1) {
        fwrite(STDERR, "Could not acquire the example setup lock.\n");
        exit(1);
    }

    try {
        $connection->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS example_organization_records (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                organization_id INT UNSIGNED NOT NULL,
                title VARCHAR(200) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY example_records_organization_id_id (organization_id, id),
                FOREIGN KEY (organization_id) REFERENCES starter_organizations (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $basic = $connection->table('starter_packages')->where('name', 'basic')->orderBy('id')->value('id');
        $packageId = $basic ?? $connection->table('starter_packages')->insertGetId([
            'name' => 'basic',
            'seat_limit' => 10,
        ]);
    } finally {
        $connection->selectOne('SELECT RELEASE_LOCK(?)', [$lock], false);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Example setup failed. Check the MySQL configuration, starter migrations, and database privileges.' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'basic_package_id=' . $packageId . PHP_EOL);
