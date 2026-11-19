<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

require __DIR__ . '/d3-fixtures.php';

function checkIssue28(int $port, SmsLoginFixture $qa, PDO $sql, Fixtures $fixtures): void
{
    $admin = d3Login($qa, $port, PHONE, 'platform');
    $actor = d3Request($port, 'GET', '/platform/me', access: $admin)['account_id'];
    $collection = '/platform/packages';
    $request = static fn (string $method, string $path, ?array $body = null, int $status = 200, string $code = 'success'): array =>
        d3Request($port, $method, $path, $body, $admin, $status, $code);
    $audit = static fn (): array => $sql->query("SELECT action, actor_account_id, target_account_id, organization_id, metadata, created_at "
        . "FROM starter_audit_logs WHERE action IN ('package_created', 'package_updated', 'package_retired') ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    $templates = static fn (): array => $sql->query('SELECT id, name, seat_limit, retired_at FROM starter_packages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $valid = ['name' => '套餐', 'seat_limit' => 1];
    d3Request($port, 'POST', $collection, $valid, status: 401, code: 'unauthenticated');
    foreach ([null, '', "　  ", "a\nb", "a\u{0085}b", "a\u{2028}b", [], str_repeat('席', 51)] as $invalid) {
        $request('POST', $collection, ['name' => $invalid] + $valid, 422, 'package_invalid');
    }
    foreach ([null, false, '1', 1.5, 0, -1, 100_001] as $invalid) {
        $request('POST', $collection, ['seat_limit' => $invalid] + $valid, 422, 'package_invalid');
    }
    $request('POST', $collection, ['name' => '只提供名称'], 422, 'package_invalid');
    $request('POST', $collection . '?name=query&seat_limit=1', [], 422, 'package_invalid');
    check($templates() === [] && $audit() === [], 'Rejected creations leave no template or audit');

    $name = str_repeat('席', 49) . '🧩';
    $package = $request('POST', $collection, [
        'name' => "　 {$name}\u{00A0}", 'seat_limit' => 100_000,
    ], 201);
    $id = $package['package_id'] ?? null;
    check(is_int($id) && $id > 0 && $package === [
        'package_id' => $id, 'name' => $name, 'seat_limit' => 100_000, 'retired' => false,
    ], 'Create a template with a trimmed, 50-code-point Unicode name and the maximum seats');
    $resource = "$collection/$id";
    $retire = "$resource/retire";

    $opened = d3Open($port, $admin, $id, 'Existing customer', '13800138001');
    $organizationId = $opened['organization_id'];
    $owner = d3Join($port, $qa, $opened['link'], '13800138001', $organizationId, 'owner');
    $invite = d3Request($port, 'POST', "/organizations/$organizationId/invitations", ['phone' => '13800138002'], $owner, 201);
    $snapshot = static fn (): array => $sql->query('SELECT package_id, package_name, seat_limit, subscription_starts_at, subscription_expires_at '
        . 'FROM starter_organizations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $seats = static fn (): array => [
        $sql->query('SELECT organization_id, account_id, role FROM starter_memberships ORDER BY organization_id, account_id')->fetchAll(PDO::FETCH_ASSOC),
        $sql->query('SELECT organization_id, status, reserved, expires_at FROM starter_invitations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ];
    $oldSnapshot = $snapshot();
    $oldSeats = $seats();
    check($oldSnapshot[0]['package_name'] === $name && (int) $oldSnapshot[0]['seat_limit'] === 100_000
        && count($oldSeats[0]) === 1 && (int) $oldSeats[1][1]['reserved'] === 1 && $invite['invitation_id'] > 0,
        'Fixture includes an assigned owner and a pending reservation under the original snapshot');
    foreach ([['POST', $collection, $valid], ['PATCH', $resource, $valid], ['POST', $retire, null]] as [$method, $path, $body]) {
        d3Request($port, $method, $path, $body, $owner, 403, 'token_purpose_mismatch');
    }
    $before = $templates();
    foreach ([[], ['name' => null], ['seat_limit' => null], ['seat_limit' => '1'], ['name' => 'Partial failure', 'seat_limit' => 0]] as $invalid) {
        $request('PATCH', $resource, $invalid, 422, 'package_invalid');
    }
    $request('PATCH', "$collection/4294967295", $valid, 404, 'not_found');
    $request('POST', "$collection/4294967295/retire", status: 404, code: 'not_found');
    $request('POST', "$collection/99999999999999999999/retire", status: 404, code: 'not_found');
    check($templates() === $before && count($audit()) === 1, 'Denied, invalid, or missing targets leave no change or extra audit');

    $privateName = 'Free text PII candidate private@example.test';
    $updated = $request('PATCH', $resource, [
        'name' => " $privateName ", 'seat_limit' => 1, 'retired' => true, 'authorization' => 'not-an-audit-field',
    ]);
    check($updated === ['package_id' => $id, 'name' => $privateName, 'seat_limit' => 1, 'retired' => false], 'Update only writable template fields');
    check($request('PATCH', $resource, ['name' => " $privateName "]) === $updated && count($audit()) === 2,
        'Normalize before deciding whether a patch makes an actual change');
    check($snapshot() === $oldSnapshot && $seats() === $oldSeats, 'Reducing template seats preserves the existing snapshot and occupied seats');

    $before = $templates();
    $sql->exec("CREATE TRIGGER fixture_issue28_audit_failure BEFORE INSERT ON starter_audit_logs FOR EACH ROW "
        . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture audit failure'");
    try {
        $request('POST', $collection, $valid, 503, 'audit_unavailable');
        $request('PATCH', $resource, ['seat_limit' => 2], 503, 'audit_unavailable');
        $request('POST', $retire, status: 503, code: 'audit_unavailable');
    } finally {
        $sql->exec('DROP TRIGGER fixture_issue28_audit_failure');
    }
    check($templates() === $before && count($audit()) === 2, 'Failing audit rolls back each template mutation');

    $new = d3Open($port, $admin, $id, 'New customer', '13800138003');
    check($new['organization_id'] !== $organizationId && $snapshot()[1]['package_name'] === $privateName
        && (int) $snapshot()[1]['seat_limit'] === 1, 'New openings use the latest active template configuration');
    $beforeRetireSnapshot = $snapshot();
    $beforeRetireSeats = $seats();
    $retired = $request('POST', $retire);
    check($retired === array_replace($updated, ['retired' => true]) && $templates()[0]['retired_at'] !== null,
        'Retire keeps the template record and marks it unavailable');
    check($request('POST', $retire) === $retired && count($audit()) === 3, 'Re-retiring a template is a no-op');
    $request('POST', '/platform/organizations', [
        'name' => 'Must not open', 'phone' => '13800138004', 'package_id' => $id,
        'starts_at' => gmdate(DATE_ATOM, SMS_TIME), 'expires_at' => gmdate(DATE_ATOM, SMS_TIME + 86400),
    ], 409, 'package_retired');
    check($snapshot() === $beforeRetireSnapshot && $seats() === $beforeRetireSeats,
        'Retirement preserves every existing snapshot and seat, and failed opening leaves no organization or invitation');

    $rows = $audit();
    check(array_column($rows, 'action') === ['package_created', 'package_updated', 'package_retired'], 'Audit exactly the committed changes');
    $metadata = [];
    foreach ($rows as $row) {
        check((int) $row['actor_account_id'] === $actor && $row['target_account_id'] === null && $row['organization_id'] === null
            && $row['created_at'] === gmdate('Y-m-d H:i:s', SMS_TIME), 'Audit identifies the platform actor, scope and timestamp');
        $meta = json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR);
        ksort($meta);
        $metadata[] = $meta;
    }
    check($metadata === [
        ['package_id' => $id, 'result' => 'success', 'seat_limit' => 100_000],
        ['name_changed' => true, 'new_seat_limit' => 1, 'old_seat_limit' => 100_000, 'package_id' => $id, 'result' => 'success'],
        ['package_id' => $id, 'result' => 'success'],
    ], 'Package audit contains allowlisted changes and never copies free-text names or request fields');

    assertPackageWriteRejectedAfterLogout28($port, $sql, $fixtures, $id, $admin);
    check($request('PATCH', $resource, ['name' => 'This session has expired'], 401, 'token_invalid') === []
        && $templates()[0]['name'] === $privateName && $audit() === $rows, 'Concurrent logout leaves template and audit unchanged');
}

function assertPackageWriteRejectedAfterLogout28(int $port, PDO $sql, Fixtures $fixtures, int $id, string $admin): void
{
    $stream = null;
    $sql->beginTransaction();
    try {
        $lock = $sql->prepare('SELECT id FROM starter_packages WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        $lock->closeCursor();
        clearstatcache(true, $fixtures->sqlLog);
        $offset = filesize($fixtures->sqlLog);
        check($offset !== false, 'MySQL query log is available for synchronization');
        $stream = smsConnect($port, 'PATCH', "/saas/platform/packages/$id", ['name' => 'Blocked package write'], sessionBearer($admin));
        $deadline = microtime(true) + 3;
        do {
            $log = file_get_contents($fixtures->sqlLog, false, null, $offset);
            check($log !== false, 'Read MySQL queries after sending blocked patch');
            if (preg_match('/\b(?:Query|Execute)\s+select [^\r\n]*from \x60starter_packages\x60[^\r\n]*for update/i', $log) === 1) {
                break;
            }
            check(microtime(true) < $deadline, 'The package mutation reached its row lock after platform authorization');
            usleep(1_000);
        } while (true);

        d3Request($port, 'POST', '/auth/logout', access: $admin);
        $sql->rollBack();
        smsExpect(smsReadResponse($stream), 401, 'token_invalid', 'Package write rejects logout while waiting for template lock');
    } finally {
        if ($sql->inTransaction()) {
            $sql->rollBack();
        }
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}

$fixtures = new Fixtures();
try {
    $qa = prepareD2Host($fixtures, 'qa_packages');
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $sql = $fixtures->database('qa_packages', create: false);
    $qa->withHost('packages', static fn (int $port) => checkIssue28($port, $qa, $sql, $fixtures), D3_HOST_ENV);
    echo "Issue #28 package management passed through the installed Webman starter routes\n";
} finally {
    $fixtures->close();
}
