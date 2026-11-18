<?php

declare(strict_types=1);

namespace StarterAcceptance;

use RuntimeException;
use Throwable;

require __DIR__ . '/fixtures.php';

/** @return array<string, mixed> */
function candidatePackage(string $archive, string $version): array
{
    $manifest = json_decode(file_get_contents("zip://$archive#composer.json"), true, flags: JSON_THROW_ON_ERROR);

    return $manifest + ['version' => $version, 'dist' => ['type' => 'zip', 'url' => 'file://' . $archive]];
}

$fixtures = null;
$status = 0;
try {
    $composer = getenv('STARTER_TEST_COMPOSER') ?: trim((string) shell_exec('command -v composer'));
    check(is_file($composer), 'Install Composer 2 or set STARTER_TEST_COMPOSER to its PHP executable');
    foreach (['pdo_mysql', 'redis', 'pcntl', 'posix', 'zip'] as $extension) {
        check(extension_loaded($extension), "Install PHP extension $extension for template acceptance");
    }
    check(posix_geteuid() !== 0, 'Run template acceptance as an unprivileged user');
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $signal) {
        pcntl_signal($signal, static function (int $signal): never {
            throw new RuntimeException("Interrupted by signal $signal");
        });
    }

    $root = dirname(__DIR__);
    $fixtures = new Fixtures();
    $template = "$fixtures->dir/template.zip";
    $fixtures->run([PHP_BINARY, "$root/tools/build-template.php", '1.1.0', $template], 'Build template ZIP');
    $library = "$fixtures->dir/library.zip";
    $fixtures->run([PHP_BINARY, $composer, 'archive', '--format=zip', "--dir=$fixtures->dir", '--file=library',
        '--no-interaction'], 'Build library ZIP', $root, 60);

    $catalog = "$fixtures->dir/packages.json";
    $starter = 'z0x/webman-starter';
    $project = 'z0x/webman-starter-template';
    $packages = [
        $starter => ['1.1.0' => candidatePackage($library, '1.1.0')],
        $project => ['1.0.0' => candidatePackage($template, '1.0.0')],
    ];
    file_put_contents($catalog, json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
    $command = [PHP_BINARY, $composer, '--no-interaction', '--no-ansi'];
    $fixtures->run([...$command, 'config', '--global', 'repositories.d2-candidate', 'composer', 'file://' . $catalog],
        'Configure isolated candidate catalog');
    $fixtures->startServices();
    $database = $fixtures->database('qa_template');
    printf("PHP %s, %s\n", PHP_VERSION, trim($fixtures->run([...$command, '--version'], 'Find Composer version')));
    clearstatcache(true, $fixtures->sqlLog);
    $offset = filesize($fixtures->sqlLog);
    check($offset !== false, 'MySQL query log unavailable');
    $fixtures->run([...$command, 'create-project', '--prefer-dist', '--no-progress', $project, $fixtures->host, '1.0.0'],
        'Non-interactive Composer create-project', timeout: 180);
    check(file_get_contents($fixtures->sqlLog, false, null, $offset) === ''
        && $database->query('SHOW TABLES')->fetchAll() === [], 'Composer create-project touched the database');
    $manifest = json_decode(file_get_contents("$fixtures->host/composer.json"), true, flags: JSON_THROW_ON_ERROR);
    check(($manifest['name'] ?? null) === $project && ($manifest['type'] ?? null) === 'project'
        && !isset($manifest['repositories'], $manifest['scripts']) && ($manifest['require'][$starter] ?? null) === '^1.1.0',
        'Generated project manifest depends on workspace configuration or does not match its package metadata');
    foreach (['LICENSE', '.env.example', 'start.php', 'windows.php', 'app/controller/IndexController.php'] as $file) {
        check(is_file("$fixtures->host/$file"), "Generated project is missing $file");
    }
    check(!file_exists("$fixtures->host/.env"), 'Generated project contains an environment file');
    $lock = json_decode(file_get_contents("$fixtures->host/composer.lock"), true, flags: JSON_THROW_ON_ERROR);
    $versions = array_column($lock['packages'], 'version', 'name');
    $dist = array_column($lock['packages'], 'dist', 'name');
    check(($versions[$starter] ?? null) === '1.1.0' && ($dist[$starter]['type'] ?? null) === 'zip',
        'Candidate library was not installed from dist');
    foreach (['webman/database', 'webman/redis'] as $package) {
        check(isset($manifest['require'][$package], $versions[$package]), "$package must be a direct installed dependency");
    }
    echo $fixtures->run([...$command, 'check-platform-reqs'], 'Verify actual PHP and extensions', $fixtures->host);
    printf("PASS project from dist with starter %s, Webman %s, database %s and Redis %s\n", $versions[$starter],
        $versions['workerman/webman-framework'], $versions['webman/database'], $versions['webman/redis']);

    $migrate = [PHP_BINARY, 'vendor/bin/phinx', 'migrate', '-c', "vendor/$starter/phinx.php"];
    $fixtures->run($migrate, 'Explicit migration', $fixtures->host);
    $fixtures->run($migrate, 'Repeat migration', $fixtures->host);
    $initialize = [PHP_BINARY, 'vendor/bin/webman-starter', 'platform:init', '13800138000'];
    $fixtures->run($initialize, 'Explicit first administrator initialization', $fixtures->host);
    check((int) $database->query('SELECT COUNT(*) FROM starter_platform_admins')->fetchColumn() === 1,
        'Explicit initialization did not create exactly one administrator');
    echo "PASS install leaves an empty database and explicit migration and initialization work\n";

    $packages[$starter]['1.1.1'] = candidatePackage($library, '1.1.1');
    file_put_contents($catalog, json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
    clearstatcache(true, $fixtures->sqlLog);
    $offset = filesize($fixtures->sqlLog);
    check($offset !== false, 'MySQL query log unavailable');
    $fixtures->run([...$command, 'update', $starter, '--no-progress'], 'Update candidate library', $fixtures->host, 180);
    $lock = json_decode(file_get_contents("$fixtures->host/composer.lock"), true, flags: JSON_THROW_ON_ERROR);
    $sql = file_get_contents($fixtures->sqlLog, false, null, $offset);
    check((array_column($lock['packages'], 'version', 'name')[$starter] ?? null) === '1.1.1'
        && $sql === '' && (int) $database->query('SELECT COUNT(*) FROM starter_platform_admins')->fetchColumn() === 1,
        'Update did not install the next candidate or touched the database');
    echo "PASS Composer update installs the next dist candidate without touching MySQL\n";

    $port = $fixtures->port();
    $webman = $fixtures->start([PHP_BINARY, 'start.php', 'start'], $fixtures->host, [
        'WEBMAN_STARTER_HTTP_PORT' => (string) $port,
        'WEBMAN_STARTER_ROUTES_ENABLED' => '1',
    ]);
    $health = static fn (): string|false => @file_get_contents("http://127.0.0.1:$port/saas/health");
    $fixtures->ready($webman, static fn (): bool => $health() !== false, 'Generated Webman project');
    $response = json_decode($health(), true, flags: JSON_THROW_ON_ERROR);
    check(($response['code'] ?? null) === 'success' && ($response['data']['status'] ?? null) === 'ok',
        'Generated project did not serve the expected health response');
    echo "PASS generated Webman project serves the opt-in SaaS health route\n";
} catch (Throwable $failure) {
    fwrite(STDERR, "Template candidate FAIL: {$failure->getMessage()}\n");
    $status = 1;
} finally {
    $fixtures?->close();
}
exit($status);
