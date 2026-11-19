<?php

declare(strict_types=1);

namespace StarterAcceptance;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

require __DIR__ . '/d3-fixtures.php';

function checkIssue29(int $port, SmsLoginFixture $qa, PDO $sql): void
{
    $admin = d3Login($qa, $port, PHONE, 'platform');
    $sql->exec("INSERT INTO starter_packages (name, seat_limit) VALUES ('Original plan', 20), ('Other plan', 5)");
    $package = (int) $sql->query("SELECT id FROM starter_packages WHERE name = 'Original plan'")->fetchColumn();
    $otherPackage = (int) $sql->query("SELECT id FROM starter_packages WHERE name = 'Other plan'")->fetchColumn();
    $open = d3Open($port, $admin, $package, 'Subscription organization', '13900139029');
    $id = $open['organization_id'];
    $otherId = d3Open($port, $admin, $otherPackage, 'Separate organization', '13900139030')['organization_id'];
    $owner = d3Join($port, $qa, $open['link'], '13900139029', $id, 'owner');
    $records = '/organizations/' . $id . '/records';
    $path = static fn (int|string $organization): string => '/platform/organizations/' . $organization . '/subscription/window';

    $readOrganization = static function (int $organization) use ($sql): array {
        $query = $sql->prepare('SELECT * FROM starter_organizations WHERE id = ?');
        $query->execute([$organization]);

        return $query->fetch(PDO::FETCH_ASSOC);
    };
    $events = static fn (): array => $sql->query("SELECT * FROM starter_audit_logs WHERE action = 'subscription_window_updated' ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    $original = $readOrganization($id);
    $otherOriginal = $readOrganization($otherId);
    $utc = static fn (int $timestamp): string => gmdate(DATE_ATOM, $timestamp);
    $local = static fn (int $timestamp): string => (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format(DATE_ATOM);
    $originalStart = SMS_TIME - 60;
    $originalEnd = SMS_TIME + 86400;
    $originalWindow = ['starts_at' => $utc($originalStart), 'expires_at' => $utc($originalEnd)];

    d3Request($port, 'PATCH', $path($id), $originalWindow, status: 401, code: 'unauthenticated');
    d3Request($port, 'PATCH', $path($id), $originalWindow, $owner, 403, 'token_purpose_mismatch');
    d3Request($port, 'PATCH', $path($otherId + 1), $originalWindow, $admin, 404, 'not_found');
    d3Request($port, 'PATCH', $path((string) PHP_INT_MAX . '0'), $originalWindow, $admin, 404, 'not_found');
    d3Request($port, 'PATCH', $path($id) . '?' . http_build_query($originalWindow), [], $admin, 422, 'subscription_window_invalid');
    $invalidStarts = [null, [], 1767268770, '2026-01-01T11:58:30', '2026-01-01T11:58:30.001Z',
        '2026-13-01T11:58:30Z', '2026-01-33T11:58:30Z', '2026-02-29T11:58:30Z', '1000-01-01T00:00:00+08:00'];
    foreach ($invalidStarts as $invalid) {
        d3Request($port, 'PATCH', $path($id), ['starts_at' => $invalid] + $originalWindow, $admin, 422, 'subscription_window_invalid');
    }
    foreach ([[], ['expires_at' => $local($originalStart)], ['expires_at' => $utc($originalStart - 1)]] as $invalidEnd) {
        d3Request($port, 'PATCH', $path($id), $invalidEnd + ['starts_at' => $originalWindow['starts_at']],
            $admin, 422, 'subscription_window_invalid');
    }
    d3Request($port, 'POST', '/platform/organizations', [
        'name' => 'Invalid D2 opening', 'phone' => '13900139031', 'package_id' => $package,
        'starts_at' => '2026-13-01T00:00:00Z', 'expires_at' => $originalWindow['expires_at'],
    ], $admin, 422, 'subscription_window_invalid');
    check($readOrganization($id) === $original && $events() === [], 'Denied or invalid windows must leave dates and audit intact');

    $sql->exec("UPDATE starter_packages SET retired_at = UTC_TIMESTAMP(), name = 'Retired plan', seat_limit = 1 WHERE id = $package");
    $extended = $originalEnd + 3600;
    $output = d3Request($port, 'PATCH', $path($id), [
        'starts_at' => $local($originalStart), 'expires_at' => $local($extended),
        'organization_id' => $otherId, 'package_id' => $otherPackage, 'seat_limit' => 1,
    ], $admin);
    $extendedResponse = ['organization_id' => $id, 'starts_at' => $utc($originalStart), 'expires_at' => $utc($extended)];
    check($output === $extendedResponse, 'Successful window change returns current dates in UTC with second precision');
    $updated = $readOrganization($id);
    $expected = ['subscription_expires_at' => gmdate('Y-m-d H:i:s', $extended)] + $original;
    check($updated == $expected && $readOrganization($otherId) === $otherOriginal,
        'Extension under a retired template preserves all other columns, snapshots and organizations');
    check((int) $sql->query('SELECT COUNT(*) FROM starter_organizations')->fetchColumn() === 2, 'A window write updates one existing row');

    $audit = $events();
    check(count($audit) === 1 && (int) $audit[0]['organization_id'] === $id
        && (int) $audit[0]['actor_account_id'] === (int) $sql->query('SELECT account_id FROM starter_platform_admins')->fetchColumn()
        && $audit[0]['target_account_id'] === null && $audit[0]['created_at'] === gmdate('Y-m-d H:i:s', SMS_TIME),
        'A real date change records one scoped audit with actor and time');
    check(json_decode($audit[0]['metadata'], true, flags: JSON_THROW_ON_ERROR) == [
        'old_starts_at' => $utc($originalStart), 'old_expires_at' => $utc($originalEnd),
        'new_starts_at' => $utc($originalStart), 'new_expires_at' => $utc($extended), 'result' => 'success',
    ], 'Audit records the previous and new UTC window');

    $sql->exec("CREATE TRIGGER reject_window_audit BEFORE INSERT ON starter_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000'");
    try {
        $same = d3Request($port, 'PATCH', $path($id), ['starts_at' => $utc($originalStart), 'expires_at' => $utc($extended)], $admin);
        check($same === $extendedResponse && $events() === $audit, 'An equivalent absolute window is a successful no-op even with auditing unavailable');
        d3Request($port, 'PATCH', $path($id), $originalWindow, $admin, 503, 'audit_unavailable');
        check($readOrganization($id) === $updated && $events() === $audit, 'An audit failure rolls back the changed window');
    } finally {
        $sql->exec('DROP TRIGGER reject_window_audit');
    }

    d3Request($port, 'POST', $records, ['title' => 'Before expiry'], $owner, 201);
    d3Request($port, 'PATCH', $path($id), ['starts_at' => $local(SMS_TIME - 120), 'expires_at' => $utc(SMS_TIME - 1)], $admin);
    d3Request($port, 'POST', $records, ['title' => 'After expiry'], $owner, 403, 'subscription_expired');
    check(array_column(d3Request($port, 'GET', $records, access: $owner)['records'], 'title') === ['Before expiry'],
        'Expiry changes the next organization request to read-only');

    d3Request($port, 'PATCH', $path($id), ['starts_at' => $local(SMS_TIME + 1), 'expires_at' => $utc(SMS_TIME + 2)], $admin);
    d3Request($port, 'GET', $records, access: $owner, status: 403, code: 'subscription_inactive');
    $qa->clock(SMS_TIME + 1);
    d3Request($port, 'POST', $records, ['title' => 'First active second'], $owner, 201);
    $qa->clock(SMS_TIME + 2);
    d3Request($port, 'POST', $records, ['title' => 'First expired second'], $owner, 403, 'subscription_expired');

    d3Request($port, 'POST', '/platform/organizations/' . $otherId . '/suspend', access: $admin);
    d3Request($port, 'POST', '/platform/organizations/' . $otherId . '/cancel-opening', access: $admin);
    d3Request($port, 'PATCH', $path($otherId), ['starts_at' => $utc(SMS_TIME), 'expires_at' => $utc(SMS_TIME + 3600)], $admin);
    $archived = $readOrganization($otherId);
    check($archived['suspended_at'] !== null && $archived['archived_at'] !== null && $archived['activated_at'] === null,
        'Window changes on a suspended and archived organization preserve lifecycle markers');
    check(count($events()) === 4, 'Exactly one audit per actual window change');
}

$fixtures = new Fixtures();
try {
    $qa = prepareD2Host($fixtures, 'qa_windows');
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $fixtures->run([PHP_BINARY, 'bin/setup-example.php'], 'Set up example records', $fixtures->host);
    $sql = $fixtures->database('qa_windows', create: false);
    $qa->withHost('windows', static fn (int $port) => checkIssue29($port, $qa, $sql), D3_HOST_ENV);
    echo "Issue #29 subscription window authorization, UTC, snapshot, expiry and audit rollback passed through the installed Webman starter routes\n";
} finally {
    $fixtures->close();
}
