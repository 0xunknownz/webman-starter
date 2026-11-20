<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;
use Z0x\WebmanStarter\saas\export\CsvEncoder;

require __DIR__ . '/d3-fixtures.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

/** @return list<list<string|null>> */
function parseD4Csv(string $body): array
{
    $stream = fopen('php://temp', 'w+b');
    try {
        fwrite($stream, $body);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    } finally {
        fclose($stream);
    }
}

/** @return array{string,string} */
function exportResponse(int $port, string $path, string $access, int $status = 200): array
{
    $socket = smsConnect($port, 'GET', '/saas' . $path, null, [...sessionBearer($access), 'X-Trace-Id: d4-export-test']);
    try {
        $response = stream_get_contents($socket);
        check(is_string($response) && !stream_get_meta_data($socket)['timed_out'], 'Download must return promptly');
    } finally {
        fclose($socket);
    }
    [$headers, $body] = explode("\r\n\r\n", $response, 2);
    check(preg_match('/^HTTP\/\S+ ' . $status . '\b/', $headers) === 1 && preg_match('/^X-Trace-Id: d4-export-test\r?$/im', $headers) === 1,
        'Download must return the expected status and propagate its trace');

    return [$headers, $body];
}

function csvAcceptance(SmsLoginFixture $qa, PDO $db, int $port): void
{
    $platform = d3Login($qa, $port, PHONE, 'platform');
    $package = d3Request($port, 'POST', '/platform/packages', ['name' => '下载验收', 'seat_limit' => 5], $platform, 201)['package_id'];
    $expiresAt = SMS_TIME + 180;
    $alpha = d3Open($port, $platform, $package, '下载甲', '13900139121', SMS_TIME + 10, $expiresAt);
    $beta = d3Open($port, $platform, $package, '下载乙', '13900139122');
    $id = $alpha['organization_id'];
    $org = "/organizations/$id";
    $members = $org . '/members/export';
    $audit = $org . '/audit-logs/export';
    $owner = d3Join($port, $qa, $alpha['link'], '13900139121', $id, 'owner');
    $outsider = d3Join($port, $qa, $beta['link'], '13900139122', $beta['organization_id'], 'owner');
    d3Request($port, 'GET', $members, access: $owner, status: 403, code: 'subscription_inactive');
    d3Request($port, 'GET', $audit, access: $owner, status: 403, code: 'subscription_inactive');
    $qa->clock(SMS_TIME + 10);
    d3Request($port, 'PATCH', '/platform' . $org . '/name', ['name' => 'qa-new-org-private-text'], $platform);
    $db->prepare('INSERT INTO starter_audit_logs (organization_id, action, metadata, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$id, 'organization_renamed', '{"old_name":"qa-legacy-org-private-old","new_name":"qa-legacy-org-private-new"}', gmdate('Y-m-d H:i:s', SMS_TIME)]);
    $invite = d3Request($port, 'POST', $org . '/invitations', ['phone' => '13900139123'], $owner, 201);
    $member = d3Join($port, $qa, $invite['link'], '13900139123', $id, 'member');
    d3Request($port, 'GET', $members, access: $member, status: 403, code: 'forbidden');
    d3Request($port, 'GET', $audit, access: $member, status: 403, code: 'forbidden');
    d3Request($port, 'GET', $members, access: $platform, status: 403, code: 'token_purpose_mismatch');
    d3Request($port, 'GET', $audit, access: $platform, status: 403, code: 'token_purpose_mismatch');
    d3Request($port, 'GET', $members, access: $outsider, status: 404, code: 'not_found');
    d3Request($port, 'GET', $audit, access: $outsider, status: 404, code: 'not_found');
    $users = array_column(d3Request($port, 'GET', $org . '/members', access: $owner)['members'], null, 'role');
    $memberPath = $org . '/members/' . $users['member']['account_id'];
    d3Request($port, 'PATCH', $memberPath . '/role', ['role' => 'admin'], $owner);
    d3Request($port, 'GET', $audit, access: $member, status: 403, code: 'forbidden');
    $update = $db->prepare('UPDATE starter_accounts SET display_name = ?, email = ?, email_verified_at = ? WHERE id = ?');
    $update->execute(["甲，\"引号\"\n行", 'owner@example.test', gmdate('Y-m-d H:i:s', SMS_TIME), $users['owner']['account_id']]);
    $update->execute([' =1+1', 'unverified@example.test', null, $users['member']['account_id']]);
    [$headers, $body] = exportResponse($port, $members, $member);
    $rows = parseD4Csv($body);
    check(stripos($headers, 'Content-Type: text/csv; charset=utf-8') !== false
        && stripos($headers, 'Content-Disposition: attachment; filename="members.csv"') !== false
        && stripos($headers, 'Cache-Control: private, no-store') !== false, 'Members download has the documented private CSV headers');
    check($rows === [
        ['account_id', 'display_name', 'role', 'phone', 'email'],
        [(string) $users['owner']['account_id'], "甲，\"引号\"\n行", 'owner', "'+8613900139121", 'owner@example.test'],
        [(string) $users['member']['account_id'], "' =1+1", 'admin', "'+8613900139123", ''],
    ], 'Only organization members and verified contacts are exported, with round-trip escaping and safer spreadsheet text');
    $contacts = d3Request($port, 'GET', $org . '/audit-logs?action=member_contacts_exported', access: $owner)['items'];
    check(count($contacts) === 1 && $contacts[0]['actor_account_id'] === $users['member']['account_id']
        && $contacts[0]['result'] === 'success' && $contacts[0]['metadata'] === ['kind' => 'member_contacts', 'row_count' => 2],
        'Successful contact download writes exactly one metadata-only audit with accurate row count');
    [$headers, $body] = exportResponse($port, $audit, $owner);
    $rows = parseD4Csv($body);
    check(stripos($headers, 'filename="audit-logs.csv"') !== false
        && array_shift($rows) === ['id', 'organization_id', 'actor_account_id', 'target_account_id', 'action', 'result', 'created_at', 'metadata']
        && array_unique(array_column($rows, 1)) === [(string) $id]
        && !in_array('organization_audit_exported', array_column($rows, 4), true)
        && !str_contains($body, '+8613900139121') && !str_contains($body, 'owner@example.test'),
        'Audit download omits credentials, foreign and current export audit records');
    $renames = array_values(array_filter($rows, static fn (array $row): bool => $row[4] === 'organization_renamed'));
    check(count($renames) === 2 && !str_contains($body, 'qa-new-org-private-text') && !str_contains($body, 'qa-legacy-org-private-'),
        'Audit CSV contains both rename events without their raw names');
    foreach ($renames as $row) {
        check(json_decode($row[7], true, flags: JSON_THROW_ON_ERROR) === ['old_name' => '[redacted]', 'new_name' => '[redacted]'],
            'Audit CSV applies the same redaction to new and historical names');
    }
    $download = d3Request($port, 'GET', $org . '/audit-logs?action=organization_audit_exported', access: $owner)['items'];
    check(count($download) === 1 && $download[0]['actor_account_id'] === $users['owner']['account_id']
        && $download[0]['metadata'] === ['kind' => 'organization_audit', 'row_count' => count($rows)], 'Audit download records its actual data row count');
    exportResponse($port, $members, $owner);
    d3Request($port, 'PATCH', $memberPath . '/role', ['role' => 'member'], $owner);
    d3Request($port, 'GET', $members, access: $member, status: 403, code: 'forbidden');
    d3Request($port, 'DELETE', $memberPath, access: $owner);
    d3Request($port, 'GET', $members, access: $member, status: 404, code: 'not_found');
    $qa->clock($expiresAt);
    exportResponse($port, $members, $owner);
    exportResponse($port, $audit, $owner);
    $count = (int) $db->query('SELECT COUNT(*) FROM starter_audit_logs')->fetchColumn();
    $db->exec("CREATE TRIGGER reject_export_audit BEFORE INSERT ON starter_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000'");
    try {
        foreach ([$members, $audit] as $path) {
            [$headers, $body] = exportResponse($port, $path, $owner, 503);
            check(stripos($headers, 'Content-Disposition:') === false
                && json_decode($body, true, flags: JSON_THROW_ON_ERROR)['code'] === 'audit_unavailable'
                && !str_contains($body, 'owner@example.test'), 'An audit failure sends the JSON error and no CSV headers or data');
        }
        check((int) $db->query('SELECT COUNT(*) FROM starter_audit_logs')->fetchColumn() === $count, 'Audit failure cannot add partial audit rows');
    } finally {
        $db->exec('DROP TRIGGER reject_export_audit');
    }
    $ops = '/platform' . $org;
    d3Request($port, 'POST', $ops . '/suspend', access: $platform);
    d3Request($port, 'GET', $members, access: $owner, status: 403, code: 'organization_suspended');
    d3Request($port, 'GET', $audit, access: $owner, status: 403, code: 'organization_suspended');
    d3Request($port, 'POST', $ops . '/archive', access: $platform);
    d3Request($port, 'GET', $members, access: $owner, status: 403, code: 'organization_archived');
    d3Request($port, 'GET', $audit, access: $owner, status: 403, code: 'organization_archived');
}

check((new CsvEncoder())->encode(['a', 'b'], []) === ['body' => "a,b\r\n", 'rowCount' => 0], 'Empty CSV contains only the header');
$hazards = ["\tfoo", "\rfoo", "\nfoo", "\xEF\xBB\xBF=1", '＝1', '-1', '+1', '@1', "中文,\"分隔\"\n", 'safe', null];
$encoded = (new CsvEncoder())->encode(['a'], array_map(static fn (?string $value): array => [$value], $hazards));
$rows = parseD4Csv($encoded['body']);
check($encoded['rowCount'] === count($hazards) && array_slice($rows, 1, 8) === array_map(static fn (string $value): array => ["'" . $value], array_slice($hazards, 0, 8))
    && $rows[9] === [$hazards[8]] && $rows[10] === ['safe'], 'CSV codec neutralizes text interpreted as formulas and preserves escaped values');

$fixtures = new Fixtures();
try {
    $qa = prepareD2Host($fixtures, 'qa_csvexport');
    $db = $fixtures->database('qa_csvexport', create: false);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $qa->withHost('d4-export', static fn (int $port) => csvAcceptance($qa, $db, $port), D3_HOST_ENV);
    echo "PASS D4 member and audit CSV, authorization, escaping, and fail-closed audit\n";
} finally {
    $fixtures->close();
}
