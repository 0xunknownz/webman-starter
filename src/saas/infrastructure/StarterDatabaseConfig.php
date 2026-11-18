<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\infrastructure;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;
use RuntimeException;

final class StarterDatabaseConfig
{
    /**
     * Read the host's MySQL settings for both migrations and runtime connections.
     *
     * @return array{driver: string, host: string, port: int, database: string, username: string,
     *     password: string, charset: string, collation: string, prefix: string, strict: bool}
     */
    public static function fromEnvironment(?string $hostRoot = null): array
    {
        $hostRoot ??= getcwd() ?: throw new RuntimeException('Cannot determine the host project directory.');

        // Process environment wins, including in .env variable interpolation. Writes stay in memory.
        $repository = RepositoryBuilder::createWithNoAdapters()
            ->addReader(PutenvAdapter::class)
            ->addAdapter(ArrayAdapter::class)
            ->immutable()
            ->make();

        try {
            Dotenv::create($repository, $hostRoot)->safeLoad();
        } catch (InvalidFileException) {
            throw new RuntimeException('The host .env file is invalid.');
        }

        $database = $repository->get('STARTER_DB_DATABASE');
        $username = $repository->get('STARTER_DB_USERNAME');

        if ($database === null || trim($database) === '') {
            throw new RuntimeException('STARTER_DB_DATABASE is required.');
        }

        if ($username === null || trim($username) === '') {
            throw new RuntimeException('STARTER_DB_USERNAME is required.');
        }

        $port = filter_var(
            $repository->get('STARTER_DB_PORT') ?? '3306',
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]
        );

        if ($port === false) {
            throw new RuntimeException('STARTER_DB_PORT must be a port from 1 to 65535.');
        }

        $host = $repository->get('STARTER_DB_HOST') ?? '127.0.0.1';
        if (trim($host) === '') {
            throw new RuntimeException('STARTER_DB_HOST must not be empty.');
        }

        return [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $repository->get('STARTER_DB_PASSWORD') ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }
}
