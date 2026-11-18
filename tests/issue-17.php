<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

require __DIR__ . '/fixtures.php';

function accessRequest17(int $port, string $path, ?string $token = null, ?array $body = null): array
{
    $curl = curl_init("http://127.0.0.1:$port$path");
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) {
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)]);
    }
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    return [$status, is_string($response) ? json_decode($response, true) : null];
}

function accessFixture17(PDO $sql, int $starts, int $ends): array
{
    $date = gmdate('Y-m-d H:i:s', $starts);
    $sql->exec("CREATE TABLE fixture_records (id INT PRIMARY KEY, organization_id INT NOT NULL, payload VARCHAR(50) NOT NULL) ENGINE=InnoDB");
    $sql->exec("CREATE TABLE fixture_invites (id INT PRIMARY KEY, organization_id INT NOT NULL, role VARCHAR(10) NOT NULL) ENGINE=InnoDB");
    $account = $sql->prepare('INSERT INTO starter_accounts (phone, created_at, updated_at) VALUES (?, ?, ?)');
    $ids = [];
    foreach (['13800138000', '13800138001', '13800138002'] as $phone) {
        $account->execute(['+86' . $phone, $date, $date]);
        $ids[] = (int) $sql->lastInsertId();
    }
    [$ownerA, $ownerB, $dual] = $ids;
    $sql->prepare("INSERT INTO starter_platform_admins (account_id, status, created_at, updated_at) VALUES (?, 'active', ?, ?)")
        ->execute([$ownerA, $date, $date]);
    $organization = $sql->prepare('INSERT INTO starter_organizations '
        . '(name, activated_at, suspended_at, archived_at, subscription_starts_at, subscription_expires_at, seat_limit) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?)');
    $orgs = [];
    foreach (['A', 'B', 'Pending', 'PendingArchived'] as $name) {
        $pending = str_starts_with($name, 'Pending');
        $archived = $name === 'PendingArchived';
        $organization->execute([$name, $pending ? null : $date, $archived ? $date : null, $archived ? $date : null,
            $date, gmdate('Y-m-d H:i:s', $ends), 2]);
        $orgs[] = (int) $sql->lastInsertId();
    }
    [$a, $b, $pending, $pendingArchived] = $orgs;
    $membership = $sql->prepare('INSERT INTO starter_memberships (organization_id, account_id, role) VALUES (?, ?, ?)');
    foreach ([[$a, $ownerA, 'owner'], [$a, $dual, 'admin'], [$b, $ownerB, 'owner'], [$b, $dual, 'member']] as $row) {
        $membership->execute($row);
    }
    $sql->prepare("INSERT INTO fixture_records VALUES (1, ?, 'record A'), (2, ?, 'record B')")->execute([$a, $b]);
    $sql->prepare("INSERT INTO fixture_invites VALUES (1, ?, 'owner'), (2, ?, 'member'), (3, ?, 'member'), (4, ?, 'owner'), (5, ?, 'owner')")
        ->execute([$pending, $pending, $a, $pendingArchived, $a]);

    return [$ownerA, $ownerB, $dual, $a, $b, $pending];
}

$fixture = new Fixtures();
try {
    $fixture->startServices();
    $root = dirname(__DIR__);
    $hostScript = __DIR__ . '/host/issue-17/start.php';
    $sql = $fixture->database('qa_access');
    $fixture->run([PHP_BINARY, 'vendor/bin/phinx', 'migrate', '-c', 'phinx.php'], 'Migrate issue #17 schema', $root);
    $starts = 1_800_000_000;
    $ends = $starts + 300;
    [$ownerA, $ownerB, $dual, $a, $b, $pending] = accessFixture17($sql, $starts, $ends);
    $port = $fixture->port();
    $fixtureInviteKey = bin2hex(random_bytes(32));
    $proof = static fn (int $invitation): string => hash_hmac('sha256', (string) $invitation, $fixtureInviteKey);
    $env = ['ISSUE17_PORT' => (string) $port, 'ISSUE17_SECRET' => bin2hex(random_bytes(32)), 'ISSUE17_INVITATION' => $fixtureInviteKey];
    [$exit, $output] = $fixture->start([PHP_BINARY, $hostScript, 'tokens', (string) $ownerA, (string) $ownerB,
        (string) $dual, (string) $starts], env: $env)->result();
    check($exit === 0, "Could not issue fixture sessions: $output");
    $tokens = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $worker = $fixture->start([PHP_BINARY, $hostScript, 'start'], env: $env);
    $fixture->ready($worker, static fn (): bool => accessRequest17($port, '/fixture/health')[0] === 200, 'Issue #17 Webman worker');
    [, $health] = accessRequest17($port, '/fixture/health');
    $pid = $health['data']['pid'];
    $expect = static function (array $response, int $status, string $code, string $label) use ($pid): ?array {
        [$actualStatus, $body] = $response;
        check($actualStatus === $status && ($body['code'] ?? null) === $code,
            "$label: expected $status $code, got $actualStatus " . json_encode($body));
        check(is_string($body['trace_id']) && $body['trace_id'] !== '', "$label: missing trace ID");
        if ($code === 'success') {
            check(($body['data']['pid'] ?? null) === $pid, "$label: worker PID changed");
        }

        return $body['data'];
    };
    $request = static fn (int $org, ?string $token, array $body = []): array => accessRequest17($port, '/fixture/organization/' . $org,
        $token === 'invitation' ? $proof(1) : ($token === null ? null : $tokens[$token]), $body + [
            'operation' => 'business_query', 'permission' => 'record.query', 'now' => $starts,
        ]);
    $invite = static fn (int $id, int $now, ?string $account = null): array => accessRequest17($port, '/fixture/invitation',
        $account === null ? $proof($id) : $tokens[$account], ['invite' => $id, 'now' => $now]);

    $result = $expect($request($a, 'ownerA', ['record' => 1]), 200, 'success', 'Owner sees A on the first active second');
    check($result === ['pid' => $pid, 'account' => $ownerA, 'organization' => $a, 'role' => 'owner', 'record' => 'record A'], 'A context');
    $expect($request($b, 'ownerA'), 404, 'not_found', 'Nonmember sees no organization B');
    $expect($request($a, 'platform'), 403, 'token_purpose_mismatch', 'Platform session confers no organization access');
    $expect($request($a, 'otherProduct'), 401, 'token_invalid', 'Different product Redis namespace cannot use session');
    $expect($request($a, 'invitation'), 401, 'token_invalid', 'An invitation credential cannot act as an organization session');
    $expect($request($a, null), 401, 'unauthenticated', 'Missing identity has first priority');
    $result = $expect($request($b, 'ownerB', ['record' => 2]), 200, 'success', 'Owner B succeeds after denied requests');
    check($result === ['pid' => $pid, 'account' => $ownerB, 'organization' => $b, 'role' => 'owner', 'record' => 'record B'], 'B context');

    $result = $expect($request($a, 'dual'), 200, 'success', 'An account’s one session can address A');
    check($result['role'] === 'admin' && $result['account'] === $dual, 'A role comes from A membership');
    $result = $expect($request($b, 'dual'), 200, 'success', 'The same session can address B on the same worker');
    check($result['role'] === 'member' && $result['account'] === $dual, 'B role comes from B membership');
    $expect($request($a, 'dual', ['record' => 2]), 404, 'not_found', 'Cross-tenant resource read');
    $write = ['operation' => 'business_write', 'permission' => 'record.write'];
    $expect($request($a, 'dual', $write + ['record' => 2]), 404, 'not_found', 'Cross-tenant resource write');
    $expect($request($a, 'dual', $write + ['record' => 1, 'mode' => 'exception']), 500, 'internal_error', 'Exception rolls back guarded write');
    $result = $expect($request($b, 'ownerB', ['record' => 2]), 200, 'success', 'B succeeds after A exception');
    check($result['record'] === 'record B' && $result['account'] === $ownerB, 'Cross-tenant write and A exception did not change B');
    $result = $expect($request($a, 'ownerA', ['record' => 1]), 200, 'success', 'A can read after exception');
    check($result['record'] === 'record A', 'Failed mutation was rolled back');
    $expect($request($a, 'dual', $write + ['record' => 1]), 200, 'success', 'Authorized write commits');
    $result = $expect($request($a, 'ownerA', ['record' => 1]), 200, 'success', 'Read committed mutation');
    check($result['record'] === 'updated', 'Authorized mutation committed');

    foreach ([['permission' => 'never.declared'], ['permission' => null]] as $permission) {
        $expect($request($a, 'ownerA', $permission), 403, 'forbidden', 'Owner needs a declared business permission');
    }
    $expect($request($a, 'ownerA', ['permission' => 'record.owner']), 200, 'success', 'Owner gets all declared permissions');
    $expect($request($a, 'dual', ['permission' => 'record.owner']), 403, 'forbidden', 'Admin lacks this declared permission');
    $expect($request($b, 'dual', ['operation' => 'business_export', 'permission' => 'record.export']), 403, 'forbidden', 'Member cannot export');
    $expect($request($a, 'dual', ['operation' => 'organization_audit']), 403, 'forbidden', 'Admin cannot query organization audit');
    $expect($request($a, 'dual', ['operation' => 'member_role_change']), 403, 'forbidden', 'Admin cannot change roles');
    $expect($request($a, 'ownerA', ['operation' => 'member_exit']), 409, 'last_owner_required', 'Owner cannot exit before transferring ownership');
    $expect($request($b, 'dual', ['operation' => 'member_contacts']), 403, 'forbidden', 'Member cannot see restricted contacts');
    $expect($request($a, 'dual', ['operation' => 'member_contacts']), 200, 'success', 'Admin can see restricted contacts');
    $inviteAtCapacity = ['operation' => 'member_invite', 'mode' => 'capacity'];
    $expect($request($a, 'dual', $inviteAtCapacity), 409, 'seat_limit_exceeded', 'Admin reaches capacity check');

    $expect($request($a, 'ownerA', ['now' => $starts - 1]), 403, 'subscription_inactive', 'Just before start');
    $expect($request($a, 'ownerA', ['now' => $ends - 1]), 200, 'success', 'Just before expiry');
    $expect($request($a, 'ownerA', ['now' => $ends]), 200, 'success', 'At expiry authorized reads survive');
    $expect($request($a, 'dual', ['operation' => 'business_export', 'permission' => 'record.export', 'now' => $ends]), 200, 'success', 'At expiry authorized export survives');
    foreach (['business_write', 'member_invite', 'invitation_link', 'invitation_reissue', 'member_role_change', 'organization_archive'] as $operation) {
        $expect($request($a, 'ownerA', ['operation' => $operation, 'now' => $ends]), 403, 'subscription_expired', "At expiry blocks $operation");
    }
    $expect($request($b, 'dual', ['operation' => 'member_invite', 'now' => $starts - 1]), 403, 'subscription_inactive', 'Subscription before role denial');
    foreach (['member_remove', 'invitation_revoke', 'ownership_transfer_start', 'ownership_transfer_revoke'] as $operation) {
        $expect($request($a, 'ownerA', ['operation' => $operation, 'now' => $ends]), 200, 'success', "At expiry allows $operation");
    }
    foreach (['member_exit', 'ownership_transfer_accept'] as $operation) {
        $expect($request($b, 'dual', ['operation' => $operation, 'now' => $ends]), 200, 'success', "At expiry allows $operation");
    }

    $expect($invite(1, $starts - 1), 200, 'success', 'First owner invitation allowed before start');
    $expect($invite(1, $starts), 200, 'success', 'First owner invitation allowed at start');
    $expect($invite(1, $ends), 403, 'subscription_expired', 'First owner invitation is blocked at expiry');
    $expect($invite(2, $starts), 403, 'organization_pending', 'Pending organization blocks member invitation');
    $expect($invite(3, $starts - 1), 403, 'subscription_inactive', 'Ordinary invitation blocked before start');
    $expect($invite(3, $starts), 200, 'success', 'Ordinary invitation allowed at start');
    $expect($invite(3, $ends), 403, 'subscription_expired', 'Ordinary invitation blocked at expiry');
    $expect($invite(4, $ends), 403, 'organization_archived', 'Archived beats suspended and expiry for first owner');
    $expect($invite(5, $starts), 409, 'invitation_invalid', 'First owner flow cannot operate on an activated organization');
    $expect($invite(1, $starts, 'ownerA'), 404, 'not_found', 'Organization session is not an invitation credential');
    $expect($request($pending, 'ownerA'), 404, 'not_found', 'Pending organizations hide existence from nonmembers');

    $sql->prepare("UPDATE starter_memberships SET role = 'member' WHERE organization_id = ? AND account_id = ?")->execute([$a, $dual]);
    $expect($request($a, 'dual', $write + ['record' => 1]), 403, 'forbidden', 'An already-issued token loses old role at next request');
    $expect($request($a, 'dual', $inviteAtCapacity), 403, 'forbidden', 'Same invitation at capacity is denied after demotion');
    $expect($request($a, 'ownerA', $write + ['record' => 1, 'now' => $ends - 1, 'locked_now' => $ends]), 403,
        'subscription_expired', 'A write is re-evaluated at the locked clock');
    $sql->prepare('DELETE FROM starter_memberships WHERE organization_id = ? AND account_id = ?')->execute([$a, $dual]);
    $expect($request($a, 'dual'), 404, 'not_found', 'An already-issued token loses removed membership at next request');
    $expect($request($b, 'dual'), 200, 'success', 'Removing A membership does not impact B');

    $sql->prepare('UPDATE starter_organizations SET suspended_at = NOW() WHERE id = ?')->execute([$a]);
    $expect($request($a, 'ownerA'), 403, 'organization_suspended', 'An already-issued token sees the newly suspended state');
    $expect($invite(3, $starts), 403, 'organization_suspended', 'Suspension applies to invitation use');
    $sql->prepare('UPDATE starter_organizations SET archived_at = NOW() WHERE id = ?')->execute([$a]);
    $expect($request($a, 'ownerA', ['now' => $ends, 'permission' => 'unknown']), 403, 'organization_archived', 'Archive beats suspension, expiry and permission');
    $expect($request($a, 'ownerB'), 404, 'not_found', 'Organization lifecycle is hidden from nonmembers');
    $expect($request($b, 'ownerB', $write + ['record' => 2, 'mode' => 'revoked']), 401, 'token_invalid', 'Write revalidates the Redis session');
    $result = $expect($request($b, 'dual', ['record' => 2]), 200, 'success', 'B member succeeds after revoked identity');
    check($result['record'] === 'record B' && $result['account'] === $dual, 'Revoked identity did not execute write or leak into next request');

    echo "Issue #17 organization authorization passed on Webman worker $pid\n";
} finally {
    $fixture->close();
}
