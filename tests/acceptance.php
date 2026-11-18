<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;
use RuntimeException;
use Throwable;

require __DIR__ . '/fixtures.php';
require __DIR__ . '/issue-3.php';
require __DIR__ . '/issue-4.php';
require __DIR__ . '/issue-5.php';
require __DIR__ . '/issue-6.php';
require __DIR__ . '/issue-7.php';
require __DIR__ . '/issue-8.php';
require __DIR__ . '/issue-9.php';
require __DIR__ . '/issue-10.php';
require __DIR__ . '/issue-11.php';
require __DIR__ . '/issue-12.php';

const PACKAGE = 'z0x/webman-starter';
const PHONE = '13800138000';
const OTHER_PHONE = '13900139000';

function packageRevision(Fixtures $fixtures, string $version): void
{
    $root = dirname(__DIR__);
    $revision = "$fixtures->dir/$version";
    check(mkdir($revision, 0700), 'Could not create package snapshot');
    foreach (['composer.json', 'src', 'bin', 'database', 'phinx.php'] as $path) {
        copyTree("$root/$path", "$revision/$path");
    }
    $file = "$fixtures->host/composer.json";
    $manifest = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $manifest['require'][PACKAGE] = $version;
    $framework = getenv('STARTER_TEST_WEBMAN_VERSION');
    if ($framework !== false && $framework !== '') {
        check(preg_match('/\A2\.[1-9][0-9]*\.[0-9]+\z/', $framework) === 1, 'Use an exact Webman 2.x version');
        $manifest['require']['workerman/webman-framework'] = $framework;
    }
    $manifest['config']['notify-on-install'] = false;
    $manifest['repositories'] = [[
        'type' => 'path',
        'url' => $revision,
        'options' => ['symlink' => false, 'versions' => [PACKAGE => $version]],
    ]];
    file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function composerPath(): string
{
    $override = getenv('STARTER_TEST_COMPOSER');
    if ($override !== false && $override !== '') {
        $path = realpath($override);
        check($path !== false && is_readable($path), 'STARTER_TEST_COMPOSER must point to a Composer PHP executable');

        return $path;
    }
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        $path = realpath($dir . '/composer');
        if ($path !== false && is_file($path) && is_executable($path)) {
            return $path;
        }
    }
    throw new RuntimeException('Install Composer 2 or set STARTER_TEST_COMPOSER to its PHP executable');
}

/** @param list<string> $args */
function composerWithoutDdl(Fixtures $fixtures, array $args): void
{
    clearstatcache(true, $fixtures->sqlLog);
    $offset = filesize($fixtures->sqlLog);
    check($offset !== false, 'MySQL query log unavailable');
    $fixtures->run([PHP_BINARY, composerPath(), ...$args, '--no-interaction', '--no-ansi'],
        'Composer ' . $args[0], $fixtures->host, 180);
    $sql = file_get_contents($fixtures->sqlLog, false, null, $offset);
    check($sql !== false, 'Could not read the MySQL query log');
    check(
        preg_match('/\t[ \t]*\d+ (?:Query|Prepare|Execute)[ \t]+(?:ALTER|CREATE|DROP|RENAME|TRUNCATE)\b/i', $sql) === 0,
        "Composer {$args[0]} attempted schema DDL",
    );
}

function migrate(Fixtures $fixtures): void
{
    $fixtures->run([
        PHP_BINARY, 'vendor/bin/phinx', 'migrate', '-c', 'vendor/' . PACKAGE . '/phinx.php',
    ], 'Explicit Phinx migration', $fixtures->host);
}

function init(Fixtures $fixtures, string $phone): Process
{
    return $fixtures->start([PHP_BINARY, 'vendor/bin/webman-starter', 'platform:init', $phone], $fixtures->host);
}

/** @param array{int,string} $result */
function cliResult(array $result, bool $success, string $code): void
{
    [$exit, $output] = $result;
    check(($exit === 0) === $success && preg_match('/\b' . preg_quote($code, '/') . '\b/', $output) === 1,
        "Expected CLI code $code, received exit $exit and output:\n$output");
    check(preg_match('/\d{11,}/', $output) === 0, 'CLI output exposed an unmasked identifier');
}

/** @return array<string,mixed> */
function snapshot(PDO $db): array
{
    $snapshot = [];
    foreach ($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$table]) {
        $identifier = '`' . str_replace('`', '``', $table) . '`';
        $ddl = $db->query("SHOW CREATE TABLE $identifier")->fetch(PDO::FETCH_NUM)[1];
        $rows = $db->query("SELECT * FROM $identifier")->fetchAll(PDO::FETCH_ASSOC);
        sort($rows);
        $snapshot[$table] = [$ddl, $rows];
    }
    ksort($snapshot);

    return $snapshot;
}

function assertCounts(PDO $db, int $accounts, int $roles, int $audits): void
{
    foreach (['starter_accounts' => $accounts, 'starter_platform_admins' => $roles, 'starter_audit_logs' => $audits] as $table => $expected) {
        check((int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn() === $expected, "Unexpected row count in $table");
    }
}

function assertAdmin(PDO $db, string $phone, bool $created): void
{
    $admins = $db->query("SELECT a.id, a.phone FROM starter_accounts a JOIN starter_platform_admins g
        ON a.id = g.account_id WHERE g.status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
    check(count($admins) === 1 && $admins[0]['phone'] === '+86' . $phone, 'Expected one active administrator with an E.164 phone');
    $audit = $db->query('SELECT action, actor_account_id, target_account_id, metadata FROM starter_audit_logs')->fetchAll(PDO::FETCH_ASSOC);
    check(count($audit) === 1, 'Expected exactly one initialization audit');
    check($audit[0]['action'] === 'platform_admin_initialized' && $audit[0]['actor_account_id'] === null
        && (string) $audit[0]['target_account_id'] === (string) $admins[0]['id'], 'Wrong initialization audit identity');
    $metadata = json_decode($audit[0]['metadata'], true, flags: JSON_THROW_ON_ERROR);
    check(($metadata['phone_masked'] ?? null) === '**********' . substr($phone, -4)
        && ($metadata['account_created'] ?? null) === $created
        && ($metadata['source'] ?? null) === 'cli' && ($metadata['result'] ?? null) === 'success'
        && ($metadata['actor_ref'] ?? null) === 'uid:' . posix_geteuid()
        && !str_contains($audit[0]['metadata'], $phone), 'Audit data did not match the redacted initialization outcome');
}

function testLifecycleAndInitialization(Fixtures $fixtures): void
{
    $db = $fixtures->database('qa_lifecycle');
    packageRevision($fixtures, 'dev-qa-initial');
    composerWithoutDdl($fixtures, ['install']);
    check(snapshot($db) === [], 'Composer installed tables into the clean database');
    echo "PASS clean Composer install leaves schema empty\n";

    migrate($fixtures);
    $empty = snapshot($db);
    check(isset($empty['starter_phinxlog'], $empty['starter_accounts'], $empty['starter_platform_admins'], $empty['starter_audit_logs']),
        'Explicit migration did not create the starter schema');
    foreach (['', '123', '+12025550123', '+8610123456789', '01088888888'] as $invalid) {
        cliResult(init($fixtures, $invalid)->result(), false, 'phone_invalid');
    }
    check(snapshot($db) === $empty, 'Invalid initialization changed the database');
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    assertCounts($db, 1, 1, 1);
    assertAdmin($db, PHONE, true);
    $initialized = snapshot($db);
    foreach (['+86' . PHONE, OTHER_PHONE] as $phone) {
        cliResult(init($fixtures, $phone)->result(), false, 'platform_admin_already_initialized');
    }
    check(snapshot($db) === $initialized, 'Repeat initialization changed a persisted administrator or audit');
    migrate($fixtures);
    check(snapshot($db) === $initialized, 'Repeating an up-to-date explicit migration changed schema or data');
    echo "PASS explicit and repeat migration, phone validation, account creation, and repeat rejection before login\n";

    packageRevision($fixtures, 'dev-qa-upgrade');
    composerWithoutDdl($fixtures, ['update', PACKAGE]);
    $lock = json_decode(file_get_contents("$fixtures->host/composer.lock"), true, flags: JSON_THROW_ON_ERROR);
    $packages = array_column($lock['packages'], 'version', 'name');
    check(($packages[PACKAGE] ?? null) === 'dev-qa-upgrade', 'Composer did not install the new package revision');
    check(snapshot($db) === $initialized, 'Composer update changed schema or data');
    echo 'PASS package upgrade preserves schema and data (Webman framework ' . $packages['workerman/webman-framework'] . ")\n";
}

function testExistingAccount(Fixtures $fixtures): void
{
    $db = $fixtures->database('qa_reuse');
    migrate($fixtures);
    $insert = $db->prepare('INSERT INTO starter_accounts (phone, created_at, updated_at) VALUES (?, NOW(), NOW())');
    $insert->execute(['+86' . PHONE]);
    $existingId = $db->lastInsertId();
    $insert->execute(['+86' . OTHER_PHONE]);
    $revokedId = $db->lastInsertId();
    $db->prepare("INSERT INTO starter_platform_admins (account_id, status, created_at, updated_at)
        VALUES (?, 'revoked', NOW(), NOW())")->execute([$revokedId]);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    assertCounts($db, 2, 2, 1);
    assertAdmin($db, PHONE, false);
    $actualId = $db->query("SELECT account_id FROM starter_platform_admins WHERE status = 'active'")->fetchColumn();
    check((string) $actualId === $existingId, 'Initialization failed to reuse the existing account ID');
    echo "PASS CLI reuses existing E.164 account and disregards a revoked qualification\n";
}

function testRollbackAndRace(Fixtures $fixtures): void
{
    $db = $fixtures->database('qa_race');
    migrate($fixtures);
    $db->exec("CREATE TRIGGER qa_reject_audit BEFORE INSERT ON starter_audit_logs FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa01 forced audit failure'");
    [$exit, $output] = init($fixtures, PHONE)->result();
    cliResult([$exit, $output], false, 'audit_unavailable');
    check(!str_contains($output, 'qa01 forced audit failure'), 'Internal database error leaked through the CLI');
    assertCounts($db, 0, 0, 0);
    $db->exec('DROP TRIGGER qa_reject_audit');
    echo "PASS forced audit failure rolls back account and qualification\n";

    // Keep the first writer in flight so both CLI processes actually overlap.
    $db->exec('CREATE TRIGGER qa_overlap BEFORE INSERT ON starter_platform_admins FOR EACH ROW DO SLEEP(1)');
    $first = init($fixtures, PHONE);
    $second = init($fixtures, OTHER_PHONE);
    check($first->running() && $second->running(), 'CLI competitors did not overlap');
    $one = $first->result();
    $two = $second->result();
    $winner = $one[0] === 0 ? PHONE : OTHER_PHONE;
    cliResult($one, $winner === PHONE, $winner === PHONE ? 'platform_admin_initialized' : 'platform_admin_already_initialized');
    cliResult($two, $winner === OTHER_PHONE, $winner === OTHER_PHONE ? 'platform_admin_initialized' : 'platform_admin_already_initialized');
    $db->exec('DROP TRIGGER qa_overlap');
    assertCounts($db, 1, 1, 1);
    assertAdmin($db, $winner, true);
    echo "PASS parallel CLI processes commit exactly one administrator and audit\n";
}

/** @return array{int, array<string, string>, string} */
function httpGet(int $port, string $path, ?string $trace = null): array
{
    $stream = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => ['Connection: close', ...($trace === null ? [] : ["X-Trace-Id: $trace"])],
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 1,
    ]]);
    $body = @file_get_contents("http://127.0.0.1:$port$path", false, $stream);
    if ($body === false) {
        return [0, [], ''];
    }
    $rawHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : $http_response_header;
    preg_match('/^HTTP\/\S+ (\d{3})/', $rawHeaders[0] ?? '', $status);
    $headers = [];
    foreach (array_slice($rawHeaders, 1) as $line) {
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower($name)] = trim($value);
    }

    return [(int) ($status[1] ?? 0), $headers, $body];
}

/** @return array<string,mixed> */
function envelope(int $port, string $path, int $status, string $code, ?string $inbound = null, ?string $expectedTrace = null): array
{
    [$actualStatus, $headers, $raw] = httpGet($port, $path, $inbound);
    check($actualStatus === $status, "GET $path expected HTTP $status, received $actualStatus: $raw");
    check(str_starts_with($headers['content-type'] ?? '', 'application/json'), "GET $path expected JSON");
    $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    check(($body['code'] ?? null) === $code && is_string($body['message'] ?? null) && array_key_exists('data', $body),
        "GET $path returned the wrong envelope: $raw");
    $trace = $body['trace_id'] ?? null;
    check(is_string($trace) && $trace === ($headers['x-trace-id'] ?? null), "GET $path disagreed on header/body trace");
    check($expectedTrace !== null ? $trace === $expectedTrace : preg_match(
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $trace,
    ) === 1, "GET $path returned the wrong propagated or generated Trace ID");

    return $body;
}

function testHttp(Fixtures $fixtures): void
{
    $fixtures->run([PHP_BINARY, '-r', <<<'PHP'
        require 'vendor/autoload.php';
        require 'support/bootstrap.php';
        $redis = support\Redis::ping();
        $mysql = support\Db::select('SELECT DATABASE() AS db_name');
        exit(config('redis.default.prefix') === 'qa01:' && in_array($redis, [true, 'PONG', '+PONG'], true)
            && ($mysql[0]->db_name ?? null) === getenv('STARTER_DB_DATABASE') ? 0 : 1);
        PHP], 'Webman host MySQL and Redis probes', $fixtures->host);
    echo "PASS Webman host reaches isolated MySQL and Redis using its configured connections\n";

    $port = $fixtures->port();
    $webman = $fixtures->start([PHP_BINARY, 'start.php', 'start'], $fixtures->host, [
        'WEBMAN_STARTER_HTTP_PORT' => (string) $port,
    ]);
    $fixtures->ready($webman, static fn (): bool => httpGet($port, '/saas/health')[0] !== 0, 'Webman (routes disabled)');
    envelope($port, '/saas/health', 404, 'route_not_found');
    $webman->stop();

    // Exercise the existing public exception helper through the same real worker.
    file_put_contents("$fixtures->host/config/route.php", <<<'PHP'


        \Webman\Route::get('/qa01/validation', static function (): never {
            throw \Z0x\WebmanStarter\Support\Exception\BusinessException::validation(['phone' => ['Invalid phone']]);
        });
        \Webman\Route::get('/qa01/unexpected', static function (): never {
            throw new \RuntimeException('qa01 private exception detail');
        });
        PHP, FILE_APPEND);
    $port = $fixtures->port();
    $webman = $fixtures->start([PHP_BINARY, 'start.php', 'start'], $fixtures->host, [
        'WEBMAN_STARTER_HTTP_PORT' => (string) $port,
        'WEBMAN_STARTER_ROUTES_ENABLED' => '1',
        'WEBMAN_STARTER_ROUTE_PREFIX' => '/qa01/starter',
    ]);
    $fixtures->ready($webman, static fn (): bool => httpGet($port, '/qa01/starter/health')[0] !== 0, 'Webman (routes enabled)');
    $id = 'qa01:upstream.001';
    $ok = envelope($port, '/qa01/starter/health', 200, 'success', $id, $id);
    check($ok['message'] === 'success' && $ok['data'] === ['status' => 'ok'], 'Wrong liveness response');
    $generated = envelope($port, '/qa01/starter/health', 200, 'success', 'bad trace header');
    $missing = envelope($port, '/qa01/starter/health', 200, 'success');
    check($generated['trace_id'] !== $missing['trace_id'], 'Trace ID leaked into subsequent requests');
    envelope($port, '/saas/health', 404, 'route_not_found');
    $unknown = envelope($port, '/unknown-host-route-qa01', 404, 'route_not_found', $id, $id);
    check($unknown['data'] === null, 'Unknown host route should have null data');
    $validation = envelope($port, '/qa01/validation', 422, 'validation_failed');
    check($validation['data'] === null && ($validation['errors'] ?? null) === ['phone' => ['Invalid phone']], 'Missing field validation errors');
    $failure = envelope($port, '/qa01/unexpected', 500, 'internal_error');
    check($failure['data'] === null && $failure['message'] === '服务内部错误', 'Unexpected HTTP error exposed exception detail');
    $webman->stop();
    echo "PASS official Webman HTTP opt-in, prefix, trace handling, validation and generic failures, and unknown-route 404\n";
}

$fixtures = null;
$status = 0;
try {
    foreach (['pdo_mysql', 'redis', 'pcntl', 'posix'] as $extension) {
        check(extension_loaded($extension), "Install PHP extension $extension for QA-01");
    }
    check(posix_geteuid() !== 0, 'Run QA-01 as an unprivileged user');
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $signal) {
        pcntl_signal($signal, static function (int $signal): never {
            throw new RuntimeException("Interrupted by signal $signal");
        });
    }
    $fixtures = new Fixtures();
    $fixtures->startServices();
    copyTree(dirname(__DIR__) . '/examples/webman', $fixtures->host, ['vendor', 'runtime', 'composer.lock', '.env', '.git']);
    testLifecycleAndInitialization($fixtures);
    testExistingAccount($fixtures);
    testRollbackAndRace($fixtures);
    testHttp($fixtures);
    $smsLogin = new SmsLoginFixture($fixtures);
    testSmsLogin($fixtures, $smsLogin);
    testSessions($fixtures, $smsLogin);
    testProfile($fixtures, $smsLogin);
    testEmailSignIn($fixtures, $smsLogin);
    testPlatformAdmins($fixtures, $smsLogin);
    testPasswordReset($fixtures, $smsLogin);
    testPhoneRebind($fixtures, $smsLogin);
    testEmailRecovery($fixtures, $smsLogin);
    testOperatorRecovery($fixtures, $smsLogin);
    testEmailChange($fixtures, $smsLogin);

    $lifecycleDb = $fixtures->database('qa_lifecycle', create: false);
    $preserved = snapshot($lifecycleDb);
    composerWithoutDdl($fixtures, ['remove', PACKAGE]);
    check(!is_dir("$fixtures->host/vendor/" . PACKAGE), 'Composer did not remove the installed package');
    check(snapshot($lifecycleDb) === $preserved, 'Composer remove changed schema or data');
    echo "PASS Composer removal preserves initialized schema and data\nQA-01 PASS\n";
} catch (Throwable $failure) {
    fwrite(STDERR, "QA-01 FAIL: {$failure->getMessage()}\n");
    $status = 1;
} finally {
    $fixtures?->close();
}
exit($status);
