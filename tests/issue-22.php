<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

require_once __DIR__ . '/d2-fixtures.php';

/** @return array{int, int, array<string, int>, array<string, string>} */
function seedOwnership(PDO $sql, int $now): array
{
    $date = gmdate('Y-m-d H:i:s', $now);
    $accounts = $sql->prepare('INSERT INTO starter_accounts (phone, display_name, created_at, updated_at) VALUES (?, ?, ?, ?)');
    $ids = [];
    $phones = ['owner' => '13800138011', 'admin' => '13800138012', 'member' => '13800138013', 'outsider' => '13800138014'];
    foreach ($phones as $key => $phone) {
        $accounts->execute(['+86' . $phone, $key === 'owner' ? '原所有者' : null, $date, $date]);
        $ids[$key] = (int) $sql->lastInsertId();
    }
    $insertOrganization = $sql->prepare('INSERT INTO starter_organizations '
        . '(name, activated_at, subscription_starts_at, subscription_expires_at, seat_limit) VALUES (?, ?, ?, ?, 10)');
    $insertOrganization->execute(['甲组织', $date, $date, gmdate('Y-m-d H:i:s', $now + 86400)]);
    $org = (int) $sql->lastInsertId();
    $insertOrganization->execute(['乙组织', $date, $date, gmdate('Y-m-d H:i:s', $now + 86400)]);
    $other = (int) $sql->lastInsertId();
    $membership = $sql->prepare('INSERT INTO starter_memberships (organization_id, account_id, role) VALUES (?, ?, ?)');
    foreach (['owner', 'admin', 'member'] as $role) {
        $membership->execute([$org, $ids[$role], $role]);
    }
    $membership->execute([$other, $ids['outsider'], 'owner']);
    $membership->execute([$other, $ids['member'], 'member']);

    return [$org, $other, $ids, $phones];
}

/** @param array<string, int> $ids @param array<string, string> $phones */
function testOwnership(SmsLoginFixture $qa, PDO $sql, int $port, int $org, int $other, array $ids, array $phones): void
{
    $now = SMS_TIME;
    $expiry = $now + 7 * 86400;
    $login = static function () use ($qa, $port, $phones): array {
        $tokens = [];
        foreach ($phones as $who => $phone) {
            $tokens[$who] = smsToken(smsLogin($port, $qa->issue($port, $phone), phone: $phone, purpose: 'organization'));
        }

        return $tokens;
    };
    $tokens = $login();
    $request = static function (string $method, string $path, ?string $who = null, ?array $body = null) use ($port, &$tokens): array {
        return smsRequest($port, $method, $path, $body, $who === null ? [] : sessionBearer($tokens[$who]));
    };
    $path = static fn (int $organization): string => '/saas/organizations/' . $organization . '/ownership-transfers';
    $start = static function (int $organization, string $who, mixed $recipient, int $status = 201, string $code = 'success') use ($request, $path): array {
        return smsExpect($request('POST', $path($organization), $who, ['recipient_account_id' => $recipient]),
            $status, $code, 'Start transfer')['data'] ?? [];
    };
    $accept = static function (int $organization, string $who, int $transfer, int $status = 200, string $code = 'success') use ($request, $path): void {
        smsExpect($request('POST', $path($organization) . '/' . $transfer . '/accept', $who), $status, $code, 'Accept transfer');
    };
    $inbox = static fn (int $organization, string $who): array =>
        smsExpect($request('GET', $path($organization) . '/pending', $who), 200, 'success', 'List transfer inbox')['data']['transfers'];
    $roles = static function (int $organization) use ($sql): array {
        $query = $sql->prepare('SELECT account_id, role FROM starter_memberships WHERE organization_id = ? ORDER BY account_id');
        $query->execute([$organization]);

        return $query->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    $initialRoles = $roles($org);

    $start($org, 'admin', $ids['member'], 403, 'forbidden');
    $start($org, 'owner', $ids['owner'], 403, 'forbidden');
    $start($org, 'owner', $ids['outsider'], 404, 'not_found');
    $start($org, 'owner', [$ids['member']], 422, 'validation_failed');
    $start($other, 'owner', $ids['member'], 404, 'not_found');
    $ownOffer = $start($org, 'owner', $ids['admin']);
    $otherOffer = $start($other, 'outsider', $ids['member'])['transfer_id'];
    $memberOffer = $start($org, 'owner', $ids['member'])['transfer_id'];
    $unscoped = smsConnect($port, 'GET', '/saas/ownership-transfers/pending', null, sessionBearer($tokens['member']));
    try {
        $statusLine = fgets($unscoped);
        check(is_string($statusLine) && preg_match('/\AHTTP\/\S+ 404\b/', $statusLine) === 1, 'Inbox without an organization returns 404');
    } finally {
        fclose($unscoped);
    }
    smsExpect($request('GET', $path($org) . '/pending'), 401, 'unauthenticated', 'Inbox requires authentication');
    smsExpect($request('GET', $path($other) . '/pending', 'admin'), 404, 'not_found', 'Inbox rejects non-members');
    smsExpect($request('GET', $path($other + 1) . '/pending', 'member'), 404, 'not_found', 'Inbox rejects unknown organizations');
    $revoked = $ownOffer['transfer_id'];
    check($ownOffer['expires_at'] === gmdate(DATE_ATOM, $expiry), 'Transfer has a seven-day lifetime');
    check($inbox($org, 'owner') === [] && $inbox($org, 'admin') === [[
        'transfer_id' => $revoked,
        'proposer_display_name' => '原所有者',
        'expires_at' => gmdate(DATE_ATOM, $expiry),
        'organization_id' => $org,
        'organization_name' => '甲组织',
    ]], 'Inbox exposes only the named recipient’s transfer and documented fields');
    check($inbox($org, 'member') === [[
        'transfer_id' => $memberOffer,
        'proposer_display_name' => '原所有者',
        'expires_at' => gmdate(DATE_ATOM, $expiry),
        'organization_id' => $org,
        'organization_name' => '甲组织',
    ]] && $inbox($other, 'member') === [[
        'transfer_id' => $otherOffer,
        'proposer_display_name' => '未设置姓名',
        'expires_at' => gmdate(DATE_ATOM, $expiry),
        'organization_id' => $other,
        'organization_name' => '乙组织',
    ]], 'An account with live offers in two organizations sees exactly the selected organization’s offer');
    $accept($org, 'member', $revoked, 404, 'not_found');
    $accept($org, 'member', $otherOffer, 404, 'not_found');
    smsExpect($request('DELETE', $path($other) . '/' . $revoked, 'outsider'), 404, 'not_found', 'Cannot revoke a foreign request');
    smsExpect($request('DELETE', $path($org) . '/' . $revoked, 'owner'), 200, 'success', 'Owner revokes own request');
    $accept($org, 'admin', $revoked, 409, 'ownership_transfer_invalid');
    check($inbox($org, 'admin') === [] && $roles($org) === $initialRoles, 'Revocation cannot change roles');

    $members = '/saas/organizations/' . $org . '/members';
    smsExpect($request('DELETE', $members . '/' . $ids['member'], 'owner'), 200, 'success', 'Owner removes invite recipient');
    $accept($org, 'member', $memberOffer, 409, 'ownership_transfer_invalid');
    $rejoin = $sql->prepare("INSERT INTO starter_memberships (organization_id, account_id, role) VALUES (?, ?, 'member')");
    $rejoin->execute([$org, $ids['member']]);
    $accept($org, 'member', $memberOffer, 409, 'ownership_transfer_invalid');
    $exitOffer = $start($org, 'owner', $ids['member'])['transfer_id'];
    smsExpect($request('DELETE', $members . '/me', 'member'), 200, 'success', 'Recipient exits organization');
    $rejoin->execute([$org, $ids['member']]);
    $accept($org, 'member', $exitOffer, 409, 'ownership_transfer_invalid');
    check($inbox($org, 'member') === [] && array_column($inbox($other, 'member'), 'transfer_id') === [$otherOffer],
        'Remove and exit invalidate only the scoped recipient’s transfers');

    $expiryOffer = $start($org, 'owner', $ids['admin'])['transfer_id'];
    $inboxExpiryOffer = $start($org, 'owner', $ids['member'])['transfer_id'];
    foreach ([
        ["suspended_at = UTC_TIMESTAMP()", 'organization_suspended'],
        ["archived_at = UTC_TIMESTAMP()", 'organization_archived'],
        ["activated_at = NULL", 'organization_pending'],
        ["subscription_starts_at = '2026-01-02 00:00:00'", 'subscription_inactive'],
    ] as [$changes, $code]) {
        $sql->exec("UPDATE starter_organizations SET $changes WHERE id = $org");
        $start($org, 'owner', $ids['admin'], 403, $code);
        $accept($org, 'admin', $expiryOffer, 403, $code);
        smsExpect($request('GET', $path($org) . '/pending', 'admin'), 403, $code, 'Inbox rejects blocked organizations');
        $reset = gmdate('Y-m-d H:i:s', $now);
        $sql->exec("UPDATE starter_organizations SET suspended_at = NULL, archived_at = NULL, activated_at = '$reset', "
            . "subscription_starts_at = '$reset' WHERE id = $org");
    }

    $qa->clock($expiry - 1);
    $tokens = $login();
    check(array_column($inbox($org, 'admin'), 'transfer_id') === [$expiryOffer], 'Transfer still appears one second before expiry');
    $transferState = static function (int $organization, int $id) use ($sql): array {
        $query = $sql->prepare('SELECT status, resolved_at FROM starter_ownership_transfers WHERE organization_id = ? AND id = ?');
        $query->execute([$organization, $id]);

        return $query->fetch(PDO::FETCH_NUM);
    };
    $qa->clock($expiry);
    $resolvedAt = gmdate('Y-m-d H:i:s', $expiry);
    check($inbox($org, 'member') === [] && $transferState($org, $inboxExpiryOffer) === ['expired', $resolvedAt]
        && $transferState($org, $expiryOffer) === ['pending', null] && $transferState($other, $otherOffer) === ['pending', null],
        'At expiry, the inbox records expiry only for the selected organization and recipient');
    $accept($org, 'admin', $expiryOffer, 409, 'ownership_transfer_invalid');
    $accept($org, 'admin', $expiryOffer, 409, 'ownership_transfer_invalid');
    check($inbox($org, 'admin') === [] && $roles($org) === $initialRoles, 'Expiry does not change ownership');

    $otherRoles = $roles($other);
    $control = $start($other, 'outsider', $ids['member'])['transfer_id'];
    $raceAdmin = $start($org, 'owner', $ids['admin'])['transfer_id'];
    $raceMember = $start($org, 'owner', $ids['member'])['transfer_id'];
    $sql->exec("CREATE TRIGGER qa_reject_transfer_audit BEFORE INSERT ON starter_audit_logs FOR EACH ROW "
        . "BEGIN IF NEW.action = 'ownership_transfer_completed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QA rollback'; END IF; END");
    try {
        $accept($org, 'member', $raceMember, 503, 'audit_unavailable');
        check($roles($org) === $initialRoles && $transferState($org, $raceMember) === ['pending', null]
            && $transferState($org, $raceAdmin) === ['pending', null], 'Failed audit rolls back roles, completion, and sibling invalidation');
    } finally {
        $sql->exec('DROP TRIGGER qa_reject_transfer_audit');
    }

    $acceptPaths = [$path($org) . '/' . $raceAdmin . '/accept', $path($org) . '/' . $raceMember . '/accept'];
    $raced = raceOwnership($qa, $sql, $port, $org, $expiry, $acceptPaths, [$tokens['admin'], $tokens['member']]);
    check(in_array($raced[0][0], [200, 409], true) && in_array($raced[1][0], [200, 409], true)
        && $raced[0][0] + $raced[1][0] === 609, 'Exactly one of the two concurrent accepts succeeds');
    $firstWon = $raced[0][0] === 200;
    $winner = $firstWon ? 'admin' : 'member';
    $loser = $firstWon ? 'member' : 'admin';
    $completed = $firstWon ? $raceAdmin : $raceMember;
    $invalidated = $firstWon ? $raceMember : $raceAdmin;
    smsExpect($raced[$firstWon ? 1 : 0], 409, 'ownership_transfer_invalid', 'Losing transfer fails with the specified code');
    $accept($org, $winner, $completed, 409, 'ownership_transfer_invalid');
    $accept($org, $loser, $invalidated, 409, 'ownership_transfer_invalid');
    $start($org, 'owner', $ids[$loser], 403, 'forbidden');
    check($transferState($org, $completed) === ['completed', $resolvedAt]
        && $transferState($org, $invalidated) === ['invalidated', $resolvedAt], 'Persist both terminal transfer states');
    check($roles($org) === array_replace($initialRoles, [$ids['owner'] => 'admin', $ids[$winner] => 'owner']),
        'Race leaves one owner and demotes the old owner');
    check($roles($other) === $otherRoles && $transferState($other, $control) === ['pending', null]
        && array_column($inbox($other, 'member'), 'transfer_id') === [$control], 'Preserve the other organization and its live offer');

    $audit = $sql->query("SELECT organization_id, action, actor_account_id, target_account_id, metadata, created_at "
        . "FROM starter_audit_logs WHERE action LIKE 'ownership_transfer_%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $expected = [
        'ownership_transfer_started:' . $revoked => [$org, $ids['owner'], $ids['admin']],
        'ownership_transfer_revoked:' . $revoked => [$org, $ids['owner'], $ids['admin']],
        'ownership_transfer_expired:' . $expiryOffer => [$org, null, $ids['admin']],
        'ownership_transfer_expired:' . $inboxExpiryOffer => [$org, null, $ids['member']],
        'ownership_transfer_expired:' . $otherOffer => [$other, null, $ids['member']],
        'ownership_transfer_completed:' . $completed => [$org, $ids[$winner], $ids[$winner]],
    ];
    foreach ($audit as $row) {
        $metadata = json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR);
        $key = $row['action'] . ':' . $metadata['transfer_id'];
        check(preg_match('/\+86|@|原所有者/', $row['metadata']) === 0, 'Organization audit cannot contain contacts or display names');
        if (isset($expected[$key])) {
            check([$row['organization_id'], $row['actor_account_id'], $row['target_account_id']] === $expected[$key],
                'Audit organization, actor and target are correctly attributed for ' . $key);
            unset($expected[$key]);
        } elseif ($row['action'] !== 'ownership_transfer_started') {
            check(false, 'Duplicate or unexpected terminal ownership audit ' . $key);
        }
        if ($row['action'] === 'ownership_transfer_expired') {
            check($metadata['expires_at'] === $expiry && $row['created_at'] === gmdate('Y-m-d H:i:s', $expiry),
                'Expiry audit records the exact expiry and observation time');
        }
    }
    check($expected === [], 'All expected ownership audit transitions were written');
}

/** @param list<string> $paths @param list<string> $tokens @return list<array{int, array<string, mixed>}> */
function raceOwnership(SmsLoginFixture $qa, PDO $sql, int $port, int $org, int $now, array $paths, array $tokens): array
{
    $gate = 'ownership-race-' . bin2hex(random_bytes(8));
    $state = $qa->stateDirectory();
    $qa->clock($now, $gate);
    $clients = [];
    try {
        $sql->beginTransaction();
        $sql->query("SELECT id FROM starter_organizations WHERE id = $org FOR UPDATE")->fetchColumn();
        foreach ($paths as $index => $path) {
            $clients[] = smsConnect($port, 'POST', $path, [], sessionBearer($tokens[$index]));
            if ($index === 0) {
                $deadline = microtime(true) + 2;
                while (glob("$state/$gate-*") === []) {
                    check(microtime(true) < $deadline, 'First Webman worker entered HTTP acceptance');
                    usleep(1000);
                }
            }
        }
        $deadline = microtime(true) + 3;
        do {
            $waiting = array_filter($sql->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool =>
                is_string($row['Info'] ?? null) && str_contains($row['Info'], "from \x60starter_organizations\x60")
                    && str_ends_with($row['Info'], 'for update'));
            check(microtime(true) < $deadline, 'Both HTTP accepts must reach the MySQL organization lock');
            if (count($waiting) !== 2) {
                usleep(1000);
            }
        } while (count($waiting) !== 2);
        $sql->commit();
        check(count(glob("$state/$gate-*")) === 2, 'Race requests reached separate Webman workers');

        return array_map(smsReadResponse(...), $clients);
    } finally {
        if ($sql->inTransaction()) {
            $sql->rollBack();
        }
        foreach ($clients as $client) {
            fclose($client);
        }
        $qa->clock($now);
    }
}

$fixture = new Fixtures();
try {
    $qa = prepareD2Host($fixture, 'qa_ownership');
    $sql = $fixture->database('qa_ownership', create: false);
    [$org, $other, $ids, $phones] = seedOwnership($sql, SMS_TIME);
    $qa->withHost('ownership', static fn (int $port) => testOwnership($qa, $sql, $port, $org, $other, $ids, $phones));
    echo "Issue #22 ownership transfer, expiry, rollback and concurrent acceptance passed through the installed Webman example\n";
} finally {
    $fixture->close();
}
