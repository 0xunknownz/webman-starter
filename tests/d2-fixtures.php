<?php

declare(strict_types=1);

namespace StarterAcceptance;

require_once __DIR__ . '/acceptance.php';

function prepareD2Host(Fixtures $fixtures, string $database, bool $published = false): SmsLoginFixture
{
    $fixtures->startServices();
    $fixtures->database($database);
    if ($published) {
        check(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 1, 'Use a clean PHP 8.1 environment for release acceptance');
        clearstatcache(true, $fixtures->sqlLog);
        $offset = filesize($fixtures->sqlLog);
        check($offset !== false, 'MySQL query log unavailable');
        [$exit, $output] = $fixtures->start([
            PHP_BINARY, composerPath(), 'create-project', '--no-interaction', '--prefer-dist',
            '--repository=https://raw.githubusercontent.com/0xunknownz/webman-starter/composer', '--add-repository',
            'z0x/webman-starter-template', $fixtures->host, '1.0.0',
        ], env: ['COMPOSER_CACHE_DIR' => "$fixtures->dir/clean-cache"])->result(180);
        check($exit === 0, "Published Composer installation failed:\n$output");
        check(file_get_contents($fixtures->sqlLog, false, null, $offset) === '', 'Composer create-project must not access MySQL');
        $lock = json_decode(file_get_contents("$fixtures->host/composer.lock"), true, flags: JSON_THROW_ON_ERROR);
        $packages = array_column($lock['packages'], null, 'name');
        $starter = $packages['z0x/webman-starter'] ?? [];
        check(($starter['version'] ?? null) === '1.1.0'
            && ($starter['dist']['url'] ?? null) === 'https://github.com/0xunknownz/webman-starter/releases/download/v1.1.0/webman-starter-1.1.0.zip'
            && isset($packages['webman/database'], $packages['webman/redis']), 'Expected published starter, MySQL and Redis packages');
        echo $fixtures->run([PHP_BINARY, composerPath(), 'check-platform-reqs', '--no-interaction'], 'Check published platform requirements', $fixtures->host);
    } else {
        copyTree(dirname(__DIR__) . '/examples/webman', $fixtures->host, ['vendor', 'runtime', 'composer.lock', '.env', '.git']);
        packageRevision($fixtures, '1.1.0');
        composerWithoutDdl($fixtures, ['install']);
    }
    migrate($fixtures);
    if ($published) {
        migrate($fixtures);
    }

    return new SmsLoginFixture($fixtures);
}
