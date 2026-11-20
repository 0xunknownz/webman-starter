<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

require __DIR__ . '/d3-fixtures.php';

const D4_CANDIDATE_VERSION = '2.1.0'; // Isolated test catalog version only.
const D4_PROJECT = 'z0x/webman-starter-template';
const D4_BASELINE = [
    'webman-starter-2.0.0.zip' => ['v2.0.0', '49a3ad5113e7dd6faf3abf9eddd40e830c0503c5'],
    'webman-starter-template-1.0.0.zip' => ['template-v1.0.0', '8304b23ac3d76af9c596c18cd413afd80747fb0d'],
];

function d4Catalog(Fixtures $fixture, ?string $mirror): string
{
    $packages = [];
    foreach (D4_BASELINE as $asset => [$tag, $checksum]) {
        $zip = ($mirror ?? $fixture->dir) . '/' . $asset;
        if ($mirror === null) {
            $url = "https://github.com/0xunknownz/webman-starter/releases/download/$tag/$asset";
            $fixture->run(['curl', '--fail', '--location', '--silent', '--show-error', '--retry', '2', '--output', $zip, $url],
                "Download $asset", timeout: 90);
        }
        check(is_file($zip) && sha1_file($zip) === $checksum, "Published checksum mismatch for $asset");
        d4AddZip($packages, $zip, $tag === 'v2.0.0' ? '2.0.0' : '1.0.0');
    }
    $source = "$fixture->dir/candidate-source";
    check(mkdir($source, 0700), 'Create an isolated candidate source snapshot');
    foreach (['composer.json', 'src', 'bin', 'database', 'phinx.php'] as $path) {
        copyTree(dirname(__DIR__) . "/$path", "$source/$path");
    }
    $fixture->run([PHP_BINARY, composerPath(), 'archive', '--format=zip', "--dir=$fixture->dir", '--file=d4-candidate', '--no-interaction'],
        'Build D4 candidate ZIP', $source);
    $candidate = "$fixture->dir/d4-candidate.zip";
    $archive = new \ZipArchive();
    check($archive->open($candidate) === true, 'Open D4 candidate ZIP');
    check($archive->locateName('src/saas/audit/AuditEvent.php') !== false
        && $archive->locateName('src/saas/export/ExportOrganizationCsvService.php') !== false, 'Candidate ZIP omits D4 code');
    $archive->close();
    d4AddZip($packages, $candidate, D4_CANDIDATE_VERSION);
    $catalog = 'file://' . $fixture->dir . '/packages.json';
    check(file_put_contents($catalog, json_encode(['packages' => $packages], JSON_THROW_ON_ERROR)) !== false, 'Write local candidate catalog');

    return $catalog;
}

function d4AddZip(array &$packages, string $zip, string $version): void
{
    $manifest = json_decode(file_get_contents("zip://$zip#composer.json"), true, flags: JSON_THROW_ON_ERROR);
    $packages[$manifest['name']][$version] = $manifest + [
        'version' => $version,
        'dist' => ['type' => 'zip', 'url' => 'file://' . $zip, 'shasum' => sha1_file($zip)],
    ];
}

function d4InstallProject(Fixtures $fixture, string $catalog, PDO $sql, string $version): SmsLoginFixture
{
    $fixture->run([PHP_BINARY, composerPath(), 'config', '--global', 'repositories.starter-d4', 'composer', $catalog], 'Configure the candidate catalog');
    clearstatcache(true, $fixture->sqlLog);
    $offset = filesize($fixture->sqlLog);
    $fixture->run([PHP_BINARY, composerPath(), 'create-project', '--no-interaction', '--no-install', '--prefer-dist',
        D4_PROJECT, $fixture->host, '1.0.0'], 'Create a clean project from the released template ZIP', timeout: 150);
    d4InstallLibrary($fixture, $version);
    check(file_get_contents($fixture->sqlLog, false, null, $offset) === '' && $sql->query('SHOW TABLES')->fetchAll() === [],
        'Composer create-project and install leave the database empty');
    migrate($fixture);
    $migrated = snapshot($sql);
    migrate($fixture);
    check(snapshot($sql) === $migrated, 'Explicit repeat migration preserves the database');
    cliResult(init($fixture, PHONE)->result(), true, 'platform_admin_initialized');
    $fixture->run([PHP_BINARY, 'bin/setup-example.php'], 'Set up example records', $fixture->host);

    return new SmsLoginFixture($fixture);
}

function d4InstallLibrary(Fixtures $fixture, string $version): void
{
    $components = [PACKAGE . ':' . $version];
    $framework = getenv('STARTER_TEST_WEBMAN_VERSION');
    if ($framework !== false && $framework !== '') {
        check(preg_match('/\A2\.[1-9][0-9]*\.[0-9]+\z/', $framework) === 1, 'Expected exact Webman 2.x version');
        $components[] = 'workerman/webman-framework:' . $framework;
    }
    composerWithoutDdl($fixture, ['require', ...$components, '--prefer-dist', '--no-progress']);
    $lock = json_decode(file_get_contents($fixture->host . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $packages = array_column($lock['packages'], null, 'name');
    $dist = $packages[PACKAGE]['dist'] ?? [];
    $asset = $version === D4_CANDIDATE_VERSION ? 'd4-candidate.zip' : 'webman-starter-2.0.0.zip';
    check($packages[PACKAGE]['version'] === $version && ($dist['type'] ?? null) === 'zip'
        && str_ends_with($dist['url'] ?? '', '/' . $asset), 'Composer must install the expected ZIP distribution');
    $fixture->run([PHP_BINARY, composerPath(), 'check-platform-reqs', '--no-interaction'], 'Check installed environment', $fixture->host);
    printf("PASS installed %s ZIP on Webman %s\n", $version, $packages['workerman/webman-framework']['version']);
}

function d4CandidateUpgrade(Fixtures $fixture, string $catalog): void
{
    $fixture->startServices();
    $sql = $fixture->database('qa_candidateupgrade');
    $qa = d4InstallProject($fixture, $catalog, $sql, '2.0.0');
    [$admin, $owner, $id] = (function () use ($qa): array {
        $saved = [];
        $qa->withHost('d4-upgrade', static function (int $port) use ($qa, &$saved): void {
            $admin = d3Login($qa, $port, PHONE, 'platform');
            $package = d3Request($port, 'POST', '/platform/packages', ['name' => '升级测试', 'seat_limit' => 2], $admin, 201)['package_id'];
            $phone = '13900139131';
            $opened = d3Open($port, $admin, $package, 'D3 历史组织', $phone);
            $id = $opened['organization_id'];
            $owner = d3Join($port, $qa, $opened['link'], $phone, $id, 'owner');
            d3Request($port, 'POST', "/organizations/$id/records", ['title' => 'Existing D3 record'], $owner, 201);
            d3Request($port, 'PATCH', "/platform/organizations/$id/name", ['name' => 'D3 历史改名'], $admin);
            $saved = [$admin, $owner, $id];
        }, D3_HOST_ENV);

        return $saved;
    })();
    $before = snapshot($sql);
    d4InstallLibrary($fixture, D4_CANDIDATE_VERSION);
    check(snapshot($sql) === $before, 'Composer upgrade preserves schema, business rows and historical audits');
    migrate($fixture);
    check(snapshot($sql) === $before, 'D4 migrations preserve a migrated D3 database');
    $qa->withHost('d4-upgrade', static function (int $port) use ($id, $admin, $owner): void {
        $path = "/organizations/$id";
        check(array_column(d3Request($port, 'GET', $path . '/records', access: $owner)['records'], 'title') === ['Existing D3 record'],
            'Existing organization session and record survive the upgrade');
        check(d3Request($port, 'GET', '/platform/audit-logs?action=organization_renamed', access: $admin)['items'][0]['organization_id'] === $id,
            'Existing platform session can read historical D3 events after upgrade');
        check(d3Request($port, 'GET', $path . '/audit-logs?action=organization_renamed', access: $owner)['items'][0]['metadata']['new_name'] === 'D3 历史改名',
            'Historical D3 organization audit is available after upgrade');
    }, D3_HOST_ENV);
    $before = snapshot($sql);
    composerWithoutDdl($fixture, ['remove', PACKAGE, '--no-progress']);
    check(snapshot($sql) === $before, 'Composer removal preserves data and schema');
}

check($argc <= 2, 'Usage: php tests/d4-release-candidate.php [published-zip-directory]');
$mirror = $argc === 2 ? realpath($argv[1]) : null;
check($mirror !== false && ($mirror === null || is_dir($mirror)), 'Expected the released D3 base and template ZIP directory');
$fixture = new Fixtures();
$fresh = new Fixtures();
try {
    $catalog = d4Catalog($fixture, $mirror);
    d4CandidateUpgrade($fixture, $catalog);
    $fresh->startServices();
    $database = $fresh->database('qa_candidatefresh');
    $qa = d4InstallProject($fresh, $catalog, $database, D4_CANDIDATE_VERSION);
    $qa->withHost('d4-fresh', static function (int $port) use ($qa): void {
        $admin = d3Login($qa, $port, PHONE, 'platform');
        check(d3Request($port, 'GET', '/platform/audit-logs?action=platform_admin_initialized', access: $admin)['items'][0]['result'] === 'success',
            'Fresh D4 candidate records initialization and supports first login');
    }, D3_HOST_ENV);
    echo "PASS D4 candidate clean install, D3 upgrade, explicit migrations, preserved sessions and Composer removal\n";
} finally {
    $fresh->close();
    $fixture->close();
}
