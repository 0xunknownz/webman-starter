<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

require __DIR__ . '/d3-fixtures.php';

const D3_UPGRADE_CATALOG = 'https://raw.githubusercontent.com/0xunknownz/webman-starter/ebe4a95520db53bf52163f33ce8c077ef5271056';
const D3_RELEASE_DOWNLOADS = 'https://github.com/0xunknownz/webman-starter/releases/download';
const D3_UPGRADE_CHECKSUMS = [
    'webman-starter-1.1.1.zip' => '25db8ef702327c9c3a7c2901e3ed20648f814525',
    'webman-starter-2.0.0.zip' => '49a3ad5113e7dd6faf3abf9eddd40e830c0503c5',
    'webman-starter-template-1.0.0.zip' => '8304b23ac3d76af9c596c18cd413afd80747fb0d',
];

function upgradeCatalog(Fixtures $fixture, ?string $mirror): string
{
    if ($mirror === null) {
        return D3_UPGRADE_CATALOG;
    }
    $directory = realpath($mirror);
    check($directory !== false && is_dir($directory), 'Expected a directory containing the published ZIPs');
    $packages = [];
    foreach (D3_UPGRADE_CHECKSUMS as $file => $checksum) {
        $path = "$directory/$file";
        check(is_file($path) && sha1_file($path) === $checksum, "Published checksum mismatch for $file");
        $manifest = json_decode(file_get_contents("zip://$path#composer.json"), true, flags: JSON_THROW_ON_ERROR);
        $version = substr($file, strrpos($file, '-') + 1, -4);
        $packages[$manifest['name']][$version] = $manifest + [
            'version' => $version, 'dist' => ['type' => 'zip', 'url' => 'file://' . $path, 'shasum' => $checksum],
        ];
    }
    $catalog = "$fixture->dir/packages.json";
    check(file_put_contents($catalog, json_encode(['packages' => $packages], JSON_THROW_ON_ERROR)) !== false,
        'Could not write the disposable Composer catalog');

    return 'file://' . $catalog;
}

function installRelease(Fixtures $fixture, PDO $sql, string $catalog, ?string $mirror): void
{
    clearstatcache(true, $fixture->sqlLog);
    $offset = filesize($fixture->sqlLog);
    $fixture->run([PHP_BINARY, composerPath(), 'config', '--global', 'repositories.starter-release', 'composer', $catalog],
        'Configure the pinned release catalog');
    $fixture->run([PHP_BINARY, composerPath(), 'create-project', '--no-interaction', '--no-progress', '--prefer-dist',
        '--no-install', 'z0x/webman-starter-template', $fixture->host, '1.0.0'], 'Install the published D2 template', timeout: 180);
    $fixture->run([PHP_BINARY, composerPath(), 'require', PACKAGE . ':1.1.1', '--no-interaction', '--prefer-dist'],
        'Install the exact published starting version', $fixture->host, 180);
    check(file_get_contents($fixture->sqlLog, false, null, $offset) === '' && $sql->query('SHOW TABLES')->fetchAll() === [],
        'Composer install touched the fresh database');
    checkReleaseLock($fixture, '1.1.1', $mirror);
    $fixture->run([PHP_BINARY, composerPath(), 'check-platform-reqs'], 'Composer platform requirements', $fixture->host);
    migrate($fixture);
    cliResult(init($fixture, PHONE)->result(), true, 'platform_admin_initialized');
    $fixture->run([PHP_BINARY, 'bin/setup-example.php'], 'Set up example business records', $fixture->host);
    check((int) $sql->query('SELECT COUNT(*) FROM starter_platform_admins')->fetchColumn() === 1, 'Expected one administrator');
}

function checkReleaseLock(Fixtures $fixture, string $version, ?string $mirror): void
{
    $lock = json_decode(file_get_contents($fixture->host . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $packages = array_column($lock['packages'], null, 'name');
    $base = $packages[PACKAGE] ?? [];
    $asset = "webman-starter-$version.zip";
    $url = $mirror === null ? D3_RELEASE_DOWNLOADS . "/v$version/$asset" : 'file://' . realpath($mirror) . '/' . $asset;
    check(($base['version'] ?? null) === $version && ($base['dist']['type'] ?? null) === 'zip'
        && ($base['dist']['url'] ?? null) === $url && ($base['dist']['shasum'] ?? null) === D3_UPGRADE_CHECKSUMS[$asset],
        'Unexpected locked base distribution');
    printf("PASS installed published base %s ZIP with Webman %s\n", $version, $packages['workerman/webman-framework']['version']);
}

function testD3ReleaseUpgrade(?string $mirror): void
{
    $fixture = new Fixtures();
    try {
        $fixture->startServices();
        $sql = $fixture->database('qa_releaseupgrade');
        installRelease($fixture, $sql, upgradeCatalog($fixture, $mirror), $mirror);
        $oldPackage = (int) $sql->query("SELECT id FROM starter_packages WHERE name = 'basic'")->fetchColumn();
        $qa = new SmsLoginFixture($fixture);
        $admin = $owner = '';
        $id = 0;
        $qa->withHost('upgradedist', static function (int $port) use ($qa, $oldPackage, &$admin, &$owner, &$id): void {
            $admin = d3Login($qa, $port, PHONE, 'platform');
            $phone = '13900139302';
            $opened = d3Open($port, $admin, $oldPackage, 'Existing D2', $phone);
            $id = $opened['organization_id'];
            $owner = d3Join($port, $qa, $opened['link'], $phone, $id, 'owner');
            d3Request($port, 'POST', "/organizations/$id/records", ['title' => 'Existing D2 record'], $owner, 201);
        }, D3_HOST_ENV);

        $before = snapshot($sql);
        clearstatcache(true, $fixture->sqlLog);
        $offset = filesize($fixture->sqlLog);
        $fixture->run([PHP_BINARY, composerPath(), 'require', PACKAGE . ':2.0.0', '--no-interaction', '--no-progress', '--prefer-dist'],
            'Upgrade installed D2 base', $fixture->host, 180);
        check(file_get_contents($fixture->sqlLog, false, null, $offset) === '', 'Composer update touched MySQL');
        checkReleaseLock($fixture, '2.0.0', $mirror);
        $fixture->run([PHP_BINARY, composerPath(), 'check-platform-reqs'], 'Composer platform requirements', $fixture->host);
        check(snapshot($sql) === $before, 'Composer update changed organization data or schema');
        migrate($fixture);
        check(snapshot($sql) === $before, 'Explicit migration changed existing rows or schema');

        $fixture->run([PHP_BINARY, '-r', <<<'CODE'
require 'vendor/autoload.php';
$connection = (new \Illuminate\Database\Connectors\ConnectionFactory(new \Illuminate\Container\Container()))
    ->make(\Z0x\WebmanStarter\saas\infrastructure\StarterDatabaseConfig::fromEnvironment());
$adminAudit = new \Z0x\WebmanStarter\saas\infrastructure\IlluminatePlatformAdminRepository($connection);
$packages = new \Z0x\WebmanStarter\saas\package\IlluminatePackageRepository($connection, $adminAudit);
$organizations = new \Z0x\WebmanStarter\saas\organization\IlluminateOrganizationRepository($connection, $packages);
$organization = $organizations->transaction(fn () => $organizations->find((int) $argv[1], lock: true));
if ($organization?->name !== 'Existing D2') {
    exit(1);
}
CODE, (string) $id], 'Check a migrated downstream constructor against the upgraded database', $fixture->host);
        $qa->withHost('upgradedist', static function (int $port) use ($admin, $owner, $id): void {
            $path = "/organizations/$id";
            check(array_column(d3Request($port, 'GET', $path . '/records', access: $owner)['records'], 'title') === ['Existing D2 record'], 'D2 session and business record survive upgrade');
            $window = d3Request($port, 'PATCH', '/platform' . $path . '/subscription/window', [
                'starts_at' => gmdate(DATE_ATOM, SMS_TIME - 600),
                'expires_at' => gmdate(DATE_ATOM, SMS_TIME + 600),
            ], $admin);
            check($window['organization_id'] === $id, 'D3 date mutation works for D2 organization');
        }, D3_HOST_ENV);
        echo "PASS published D2 to D3 dist upgrade preserves database, records, sessions and migrated direct assembly\n";
    } finally {
        $fixture->close();
    }
}

check($argc <= 2, 'Usage: php tests/d3-release-upgrade.php [published-zip-directory]');
testD3ReleaseUpgrade($argv[1] ?? null);
