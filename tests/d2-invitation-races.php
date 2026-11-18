<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

require __DIR__ . '/d2-fixtures.php';

const D2_RACE_PAGE = 'https://product.example.test/join';
const D2_RACE_OPEN = '/saas/platform/organizations';
const D2_RACE_INVITE = '/saas/invitations';

function d2RaceOpen(int $port, string $platform, int $package, string $name, string $phone): array
{
    return smsExpect(smsRequest($port, 'POST', D2_RACE_OPEN, [
        'name' => $name, 'phone' => $phone, 'package_id' => $package,
        'starts_at' => gmdate(DATE_ATOM, SMS_TIME - 60), 'expires_at' => gmdate(DATE_ATOM, SMS_TIME + 86400),
    ], sessionBearer($platform)), 201, 'success', 'Open one-seat organization')['data'];
}

function d2RaceToken(array $invitation): string
{
    check(str_starts_with($invitation['link'], D2_RACE_PAGE . '#token='), 'Expected the configured invitation page');

    return substr((string) parse_url($invitation['link'], PHP_URL_FRAGMENT), strlen('token='));
}

function d2RaceProof(SmsLoginFixture $qa, int $port, array $invitation, string $phone): array
{
    $input = ['token' => d2RaceToken($invitation), 'phone' => $phone];
    $before = count($qa->messages());
    $send = smsExpect(smsRequest($port, 'POST', D2_RACE_INVITE . '/sms/send', $input), 200, 'success', 'Send invitation SMS')['data'];
    $messages = $qa->messages();
    check(count($messages) === $before + 1 && $messages[$before]['phone'] === '+86' . $phone
        && $messages[$before]['purpose'] === 'invitation', 'Invitation verification sends one SMS to its intended destination');
    $proof = smsExpect(smsRequest($port, 'POST', D2_RACE_INVITE . '/sms/verify', $input + [
        'challenge_id' => $send['challenge_id'], 'code' => $messages[$before]['code'],
    ]), 200, 'success', 'Verify invitation SMS')['data'];

    return $input + ['credential' => $proof['credential']];
}

/** @return list<array{int, array<string, mixed>}> */
function d2RaceRequests(PDO $sql, bool $phoneGate, int|string $key, array $ports, array $requests): array
{
    $clients = [];
    $table = $phoneGate ? 'starter_accounts' : 'starter_organizations';
    $column = $phoneGate ? 'phone' : 'id';
    $pending = $sql->prepare('SELECT ID, INFO FROM information_schema.PROCESSLIST '
        . 'WHERE ID <> CONNECTION_ID() AND DB = DATABASE() AND INFO LIKE ?');
    $awaitLock = static function (string $expectedTable, array $known) use ($pending): int {
        $deadline = microtime(true) + 3;
        do {
            $pending->execute(['%from %' . $expectedTable . '%for update%']);
            foreach ($pending->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!in_array($row['ID'], $known, true)) {
                    return $row['ID'];
                }
            }
            check(microtime(true) < $deadline, 'A race contender did not reach its ' . $expectedTable . ' lock');
            usleep(1_000);
        } while (true);
    };
    $sql->beginTransaction();
    try {
        $gate = $sql->prepare("SELECT id FROM $table WHERE $column = ? FOR UPDATE");
        $gate->execute([$key]);
        check($gate->fetchColumn() !== false, 'Race fixture must lock an existing row');
        $gate->closeCursor();
        $waiting = [];
        foreach ($requests as $index => [$path, $input, $headers, $expectedTable]) {
            $clients[] = smsConnect($ports[$index], 'POST', $path, $input, $headers);
            $waiting[] = $awaitLock($expectedTable, $waiting);
        }
        // Both installed Webman worker processes are inside their production transactions.
        // The first request queued for the gated row proceeds before the second contender.
        $sql->commit();

        return array_map(smsReadResponse(...), $clients);
    } finally {
        if ($sql->inTransaction()) {
            $sql->rollBack();
        }
        foreach ($clients as $client) {
            fclose($client);
        }
    }
}

function d2RaceAccount(PDO $sql, string $phone): ?int
{
    $query = $sql->prepare('SELECT id FROM starter_accounts WHERE phone = ?');
    $query->execute(['+86' . $phone]);
    $accounts = $query->fetchAll(PDO::FETCH_COLUMN);
    check(count($accounts) <= 1, 'A phone must identify one global account at most');

    return $accounts[0] ?? null;
}

function d2RaceState(PDO $sql, int $organization, bool $archived, ?int $owner, array $expectedInvitations): void
{
    $query = $sql->prepare('SELECT activated_at, archived_at, suspended_at, seat_limit, subscription_starts_at, subscription_expires_at '
        . 'FROM starter_organizations WHERE id = ?');
    $query->execute([$organization]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    check($row !== false && ($row['archived_at'] !== null) === $archived && ($row['activated_at'] !== null) === ($owner !== null)
        && $row['suspended_at'] === null && (int) $row['seat_limit'] === 1
        && $row['subscription_starts_at'] === gmdate('Y-m-d H:i:s', SMS_TIME - 60)
        && $row['subscription_expires_at'] === gmdate('Y-m-d H:i:s', SMS_TIME + 86400),
        'Race keeps organization, seat limit, and subscription consistent');
    $memberships = $sql->prepare('SELECT account_id, role FROM starter_memberships WHERE organization_id = ?');
    $memberships->execute([$organization]);
    $members = $memberships->fetchAll(PDO::FETCH_ASSOC);
    check($members === ($owner === null ? [] : [['account_id' => $owner, 'role' => 'owner']]), 'Only a successful acceptance establishes one owner');
    $invitations = $sql->prepare('SELECT id, phone, status, reserved, account_id, role '
        . 'FROM starter_invitations WHERE organization_id = ? ORDER BY id');
    $invitations->execute([$organization]);
    check($invitations->fetchAll(PDO::FETCH_NUM) === $expectedInvitations, 'Invitation history, target, account and reservations match the winning operation');
    $occupancy = count($members) + array_sum(array_column($expectedInvitations, 3));
    check($occupancy <= 1, 'Race keeps seat occupancy within the snapshot limit');
}

function d2RaceAudits(PDO $sql, int $organization, array $required, array $absent, array $redactedValues): void
{
    $query = $sql->prepare('SELECT action, metadata FROM starter_audit_logs WHERE organization_id = ? ORDER BY id');
    $query->execute([$organization]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $actions = array_count_values(array_column($rows, 'action'));
    foreach ($required as $action) {
        check(($actions[$action] ?? 0) === 1, 'Winning action must have exactly one audit event: ' . $action);
    }
    foreach ($absent as $action) {
        check(!isset($actions[$action]), 'Rejected action must have no success audit event: ' . $action);
    }
    foreach ($rows as $row) {
        foreach ($redactedValues as $value) {
            check(!str_contains($row['metadata'], $value), 'Audit metadata must omit raw contact numbers, links and proofs');
        }
    }
}

function d2RacePlatform(SmsLoginFixture $qa, PDO $sql, array $ports, string $platform, int $package): void
{
    foreach ([
        ['cancel', false, '13800138401', '13800138411'],
        ['cancel', true, '13800138402', '13800138412'],
        ['change', false, '13800138403', '13800138413'],
        ['change', true, '13800138404', '13800138414'],
    ] as [$kind, $acceptFirst, $oldPhone, $newPhone]) {
        $opened = d2RaceOpen($ports[0], $platform, $package, $kind . ($acceptFirst ? ' accept first' : ' platform first'), $oldPhone);
        $organization = $opened['organization_id'];
        $oldId = $opened['invitation_id'];
        $path = D2_RACE_OPEN . '/' . $organization;
        $proof = d2RaceProof($qa, $ports[0], $opened, $oldPhone);
        // Phone changes conflict on an existing account lock after acceptance acquires it.
        $oldAccount = $kind === 'change' && $acceptFirst ? rebindAccount($sql, $oldPhone) : null;
        $accept = [D2_RACE_INVITE . '/accept', $proof, [], 'starter_organizations'];
        $operation = $kind === 'cancel'
            ? [$path . '/cancel-opening', [], sessionBearer($platform), 'starter_organizations']
            : [$path . '/owner-invitations/' . $oldId . '/change-phone', ['phone' => $newPhone, 'reason' => 'wrong_number'],
                sessionBearer($platform), $acceptFirst ? 'starter_accounts' : 'starter_organizations'];
        $responses = d2RaceRequests($sql, false, $organization, $ports, $acceptFirst ? [$accept, $operation] : [$operation, $accept]);
        [$acceptResponse, $platformResponse] = $acceptFirst ? $responses : array_reverse($responses);
        $sensitive = [$oldPhone, $newPhone, $proof['token'], $proof['credential']];
        $action = $kind === 'cancel' ? 'organization_opening_cancelled' : 'organization_owner_invitee_changed';
        if ($acceptFirst) {
            $result = smsExpect($acceptResponse, 200, 'success', 'First acceptance commits')['data'];
            smsExpect($platformResponse, 403, 'forbidden', 'Platform mutation cannot undo activation');
            $owner = d2RaceAccount($sql, $oldPhone);
            check($owner !== null && $owner === $result['account_id'] && ($oldAccount === null || $owner === $oldAccount)
                && $result['organization_id'] === $organization && $result['role'] === 'owner', 'First acceptance identifies the unique owner');
            d2RaceState($sql, $organization, false, $owner, [[$oldId, '+86' . $oldPhone, 'accepted', 0, $owner, 'owner']]);
            d2RaceAudits($sql, $organization, ['invitation_accepted'], [$action], $sensitive);
        } else {
            smsExpect($acceptResponse, 409, 'invitation_invalid', 'Old proof cannot accept after platform mutation');
            check(d2RaceAccount($sql, $oldPhone) === null, 'Failed old acceptance leaves no partial account');
            $history = [[$oldId, '+86' . $oldPhone, 'revoked', 0, null, 'owner']];
            if ($kind === 'cancel') {
                $state = smsExpect($platformResponse, 200, 'success', 'Cancellation commits before acceptance')['data'];
                check($state['archived'] === true && $state['phase'] === 'pending', 'Cancellation archives the pending organization');
                d2RaceState($sql, $organization, true, null, $history);
            } else {
                $replacement = smsExpect($platformResponse, 201, 'success', 'Change-phone commits before acceptance')['data'];
                $history[] = [$replacement['invitation_id'], '+86' . $newPhone, 'pending', 1, null, 'owner'];
                d2RaceState($sql, $organization, false, null, $history);
                $newToken = d2RaceToken($replacement);
                $sensitive[] = $newToken;
                $preview = smsExpect(smsRequest($ports[0], 'POST', D2_RACE_INVITE . '/resolve', ['token' => $newToken]),
                    200, 'success', 'Only the replacement link is usable')['data'];
                check($newToken !== $proof['token'] && $preview['phone_masked'] === '**********' . substr($newPhone, -4),
                    'Replacement is bound to the new phone and returns a masked preview');
            }
            d2RaceAudits($sql, $organization, [$action], ['invitation_accepted'], $sensitive);
        }
        check(d2RaceAccount($sql, $newPhone) === null, 'Platform operation never creates an invited account');
        smsExpect(smsRequest($ports[0], 'POST', D2_RACE_INVITE . '/resolve', ['token' => $proof['token']]),
            409, 'invitation_invalid', 'Old invitation stays invalid');
    }
    echo "PASS cancel-opening and change-phone each race old invitation acceptance in both orders\n";
}

function d2RaceRebind(SmsLoginFixture $qa, PDO $sql, array $ports, string $platform, int $package): void
{
    foreach ([false, true] as $index => $acceptFirst) {
        $oldPhone = '1380013842' . $index;
        $newPhone = '1380013843' . $index;
        $account = rebindAccount($sql, $oldPhone);
        $login = sessionTokens(smsLogin($ports[0], $qa->issue($ports[0], $oldPhone), phone: $oldPhone, purpose: 'organization'));
        $open = d2RaceOpen($ports[0], $platform, $package, 'Rebind race ' . $index, $oldPhone);
        $organization = $open['organization_id'];
        $oldId = $open['invitation_id'];
        $proof = d2RaceProof($qa, $ports[0], $open, $oldPhone);
        $flow = rebindProof($qa, $ports[0], $login['access_token'], $oldPhone, $newPhone);
        $accept = [D2_RACE_INVITE . '/accept', $proof, [], 'starter_accounts'];
        $rebind = [REBIND_PATH . "$flow/complete", [], sessionBearer($login['access_token']), 'starter_accounts'];
        $responses = d2RaceRequests($sql, true, '+86' . $oldPhone, $ports, $acceptFirst ? [$accept, $rebind] : [$rebind, $accept]);
        [$acceptResponse, $rebindResponse] = $acceptFirst ? $responses : array_reverse($responses);
        smsExpect($rebindResponse, 200, 'success', 'Normal phone rebind commits');
        check(d2RaceAccount($sql, $oldPhone) === null && d2RaceAccount($sql, $newPhone) === $account, 'Rebind migrates exactly the original account');
        $sensitive = [$oldPhone, $newPhone, $proof['token'], $proof['credential']];
        if ($acceptFirst) {
            $accepted = smsExpect($acceptResponse, 200, 'success', 'Acceptance can precede rebind')['data'];
            check($accepted['account_id'] === $account && $accepted['role'] === 'owner', 'Acceptance reuses the original account');
            d2RaceState($sql, $organization, false, $account, [[$oldId, '+86' . $oldPhone, 'accepted', 0, $account, 'owner']]);
            d2RaceAudits($sql, $organization, ['invitation_accepted'], [], $sensitive);
        } else {
            smsExpect($acceptResponse, 409, 'invitation_invalid', 'Normal rebind invalidates the old invitation before acceptance');
            d2RaceState($sql, $organization, false, null, [[$oldId, '+86' . $oldPhone, 'revoked', 0, null, 'owner']]);
            d2RaceAudits($sql, $organization, [], ['invitation_accepted'], $sensitive);
        }
        $audit = $sql->prepare("SELECT metadata FROM starter_audit_logs WHERE action = 'phone_changed' AND target_account_id = ?");
        $audit->execute([$account]);
        $metadata = $audit->fetchAll(PDO::FETCH_COLUMN);
        check(count($metadata) === 1 && !str_contains($metadata[0], $oldPhone) && !str_contains($metadata[0], $newPhone),
            'Successful rebind produces one redacted global audit event');
        sessionRefresh($ports[0], $login['refresh_token'], 401, 'token_invalid');
    }
    echo "PASS normal phone rebind races old invitation acceptance in both orders\n";
}

$fixture = new Fixtures();
try {
    $qa = prepareD2Host($fixture, 'qa_invitationraces');
    $sql = $fixture->database('qa_invitationraces', create: false);
    cliResult(init($fixture, PHONE)->result(), true, 'platform_admin_initialized');
    $sql->exec("INSERT INTO starter_packages (name, seat_limit) VALUES ('Race fixture', 1)");
    $package = (int) $sql->lastInsertId();
    $qa->clock(SMS_TIME);

    // Process runtime paths are isolated in the disposable installed host. SQL, Redis and the fixture clock are shared.
    $appConfig = $fixture->host . '/config/app.php';
    check(copy($appConfig, $fixture->host . '/config/app.race-original.inc'), 'Preserve the disposable host configuration');
    check(file_put_contents($appConfig, <<<'PHP'
        <?php
        $app = require __DIR__ . '/app.race-original.inc';
        $app['runtime_path'] .= '-' . getenv('WEBMAN_STARTER_HTTP_PORT');
        return $app;
        PHP) !== false, 'Isolate PID files for the two Webman workers');
    $ports = [$fixture->port(), $fixture->port()];
    foreach ($ports as $port) {
        $worker = $qa->start($port, 'd2-invitation-races', [
            'STARTER_INVITATION_PAGE_URL' => D2_RACE_PAGE, 'WEBMAN_STARTER_HTTP_WORKERS' => '1',
        ]);
        $fixture->ready($worker, static fn (): bool => httpGet($port, '/saas/health')[0] === 200, 'Installed Webman race worker');
    }
    $platform = smsToken(smsLogin($ports[0], $qa->issue($ports[0], PHONE), phone: PHONE, purpose: 'platform'));
    d2RacePlatform($qa, $sql, $ports, $platform, $package);
    d2RaceRebind($qa, $sql, $ports, $platform, $package);
    echo "PASS installed D2 invitation races with real Webman, MySQL, and Redis\n";
} finally {
    $fixture->close();
}
