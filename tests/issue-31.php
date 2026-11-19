<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

require __DIR__ . '/d3-fixtures.php';

const ISSUE31_SWITCH = 'subscription_package_changed';

function issue31PackagePath(int $id): string
{
    return "/platform/organizations/$id/subscription/package";
}

function issue31CreatePackage(int $port, string $platform, string $name, int $limit): int
{
    return d3Request($port, 'POST', '/platform/packages', ['name' => $name, 'seat_limit' => $limit], $platform, 201)['package_id'];
}

function issue31State(PDO $sql, int $id): array
{
    // Read durable state outside the HTTP transaction, including all membership, invitation and audit fields.
    return [
        'organization' => $sql->query("SELECT * FROM starter_organizations WHERE id = $id")->fetch(PDO::FETCH_ASSOC),
        'members' => $sql->query("SELECT * FROM starter_memberships WHERE organization_id = $id ORDER BY account_id")->fetchAll(PDO::FETCH_ASSOC),
        'invitations' => $sql->query("SELECT * FROM starter_invitations WHERE organization_id = $id ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        'audits' => $sql->query("SELECT * FROM starter_audit_logs WHERE organization_id = $id ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function issue31CheckSwitch(array $data, array $before, array $after, int $actor, int $package, string $name, int $limit): void
{
    $old = $before['organization'];
    $snapshot = ['package_id' => $package, 'package_name' => $name, 'seat_limit' => $limit];
    check($data === ['organization_id' => $old['id']] + $snapshot, 'Return the organization ID and the exact new template snapshot');
    check($after['organization'] === array_replace($old, $snapshot), 'Only the current package snapshot changes, preserving both subscription dates');
    check($after['members'] === $before['members'] && $after['invitations'] === $before['invitations'],
        'Switch keeps all memberships and invitation history unchanged');
    $audit = array_slice($after['audits'], count($before['audits']));
    check(array_slice($after['audits'], 0, count($before['audits'])) === $before['audits'] && count($audit) === 1,
        'A committed switch only appends one organization audit');
    $event = $audit[0];
    check($event['action'] === ISSUE31_SWITCH && $event['actor_account_id'] === $actor && $event['target_account_id'] === null
        && $event['organization_id'] === $old['id'] && $event['created_at'] === gmdate('Y-m-d H:i:s', SMS_TIME),
        'Audit records the switch, platform actor, organization, and fixture time');
    $metadata = json_decode($event['metadata'], true, flags: JSON_THROW_ON_ERROR);
    ksort($metadata);
    check($metadata === [
        'new_package_id' => $package, 'new_seat_limit' => $limit,
        'old_package_id' => $old['package_id'], 'old_seat_limit' => $old['seat_limit'], 'result' => 'success',
    ], 'Audit metadata contains only the old and new IDs and seat limits plus the result, with neither raw name');
}

function issue31Switch(SmsLoginFixture $qa, PDO $sql, int $port, string $platform, int $actor): void
{
    $original = issue31CreatePackage($port, $platform, 'Original private@example.test', 7);
    $target = issue31CreatePackage($port, $platform, 'Initial target template', 5);
    $tooSmall = issue31CreatePackage($port, $platform, 'Two seats', 2);
    $retired = issue31CreatePackage($port, $platform, 'Retired template', 8);
    d3Request($port, 'POST', "/platform/packages/$retired/retire", access: $platform);

    $ownerPhone = '13800138310';
    $memberPhone = '13800138311';
    $opened = d3Open($port, $platform, $original, 'Package switch fixture', $ownerPhone);
    $id = $opened['organization_id'];
    $owner = d3Join($port, $qa, $opened['link'], $ownerPhone, $id, 'owner');
    $invitationPath = "/organizations/$id/invitations";
    $invite = static fn (string $phone): array => d3Request($port, 'POST', $invitationPath, ['phone' => $phone], $owner, 201);
    $joined = $invite($memberPhone);
    $member = d3Join($port, $qa, $joined['link'], $memberPhone, $id, 'member');
    $expired = $invite('13800138312')['invitation_id'];
    $revoked = $invite('13800138313')['invitation_id'];
    $pending = $invite('13800138314')['invitation_id'];
    d3Request($port, 'DELETE', "$invitationPath/$revoked", access: $owner);

    // Leave the expired invitation reserved in SQL to exercise the exact expiry boundary without a cleanup task.
    $expire = $sql->prepare('UPDATE starter_invitations SET expires_at = ? WHERE organization_id = ? AND id = ?');
    $expire->execute([gmdate('Y-m-d H:i:s', SMS_TIME), $id, $expired]);
    $expire->execute([gmdate('Y-m-d H:i:s', SMS_TIME + 1), $id, $pending]);
    $before = issue31State($sql, $id);
    $history = array_column($before['invitations'], null, 'id');
    check(array_column($before['members'], 'role') === ['owner', 'member']
        && $history[$opened['invitation_id']]['status'] === 'accepted' && $history[$joined['invitation_id']]['status'] === 'accepted'
        && $history[$expired]['status'] === 'pending' && $history[$expired]['reserved'] === 1
        && $history[$expired]['expires_at'] === gmdate('Y-m-d H:i:s', SMS_TIME)
        && $history[$revoked]['status'] === 'revoked' && $history[$revoked]['reserved'] === 0
        && $history[$pending]['status'] === 'pending' && $history[$pending]['reserved'] === 1
        && $history[$pending]['expires_at'] === gmdate('Y-m-d H:i:s', SMS_TIME + 1), 'Fixture has exactly three occupied seats and both released reservation states');

    $path = issue31PackagePath($id);
    $input = ['package_id' => $target];
    d3Request($port, 'PATCH', $path, $input, status: 401, code: 'unauthenticated');
    foreach ([$owner, $member] as $organizationSession) {
        d3Request($port, 'PATCH', $path, $input, $organizationSession, 403, 'token_purpose_mismatch');
    }
    foreach ([[], ['package_id' => (string) $target], ['package_id' => 1.5], ['package_id' => 0]] as $invalid) {
        d3Request($port, 'PATCH', $path, $invalid, $platform, 422, 'validation_failed');
    }
    d3Request($port, 'PATCH', $path, ['package_id' => $retired + 1], $platform, 404, 'not_found');
    d3Request($port, 'PATCH', $path, ['package_id' => $retired], $platform, 409, 'package_retired');
    d3Request($port, 'PATCH', $path, ['package_id' => $tooSmall], $platform, 409, 'seat_limit_exceeded');
    check(issue31State($sql, $id) === $before, 'Every rejected switch leaves subscription, members, invitations and audit unchanged');
    echo "PASS issue #31 authorization, integer validation, retired package and over-capacity rejection\n";

    $name = 'Current target contact@example.test';
    d3Request($port, 'PATCH', "/platform/packages/$target", ['name' => $name, 'seat_limit' => 3], $platform);
    $sql->exec("CREATE TRIGGER issue31_reject_switch_audit BEFORE INSERT ON starter_audit_logs FOR EACH ROW
        BEGIN IF NEW.action = 'subscription_package_changed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture audit failure'; END IF; END");
    try {
        d3Request($port, 'PATCH', $path, $input, $platform, 503, 'audit_unavailable');
        check(issue31State($sql, $id) === $before, 'A rejected audit insert rolls back every part of the switch');
    } finally {
        $sql->exec('DROP TRIGGER issue31_reject_switch_audit');
    }

    $data = d3Request($port, 'PATCH', $path, $input, $platform);
    $after = issue31State($sql, $id);
    issue31CheckSwitch($data, $before, $after, $actor, $target, $name, 3);
    check(d3Request($port, 'PATCH', $path, $input, $platform) === $data && issue31State($sql, $id) === $after,
        'An identical snapshot is an idempotent success without a duplicate audit');

    $newName = 'Updated active template';
    d3Request($port, 'PATCH', "/platform/packages/$target", ['name' => $newName, 'seat_limit' => 4], $platform);
    check(issue31State($sql, $id) === $after, 'Editing the selected template does not mutate any current snapshot');
    $reapplied = d3Request($port, 'PATCH', $path, $input, $platform);
    issue31CheckSwitch($reapplied, $after, issue31State($sql, $id), $actor, $target, $newName, 4);
    echo "PASS issue #31 exactly three occupied seats, live snapshot, unchanged dates, audit redaction and atomic rollback\n";
}

/** @return list<array{int,array<string,mixed>}> */
function issue31RaceRequests(PDO $sql, int $id, array $ports, array $requests): array
{
    $clients = [];
    $waiting = $sql->prepare('SELECT ID FROM information_schema.PROCESSLIST '
        . 'WHERE ID <> CONNECTION_ID() AND DB = DATABASE() AND INFO LIKE ?');
    $sql->beginTransaction();
    try {
        $gate = $sql->query("SELECT id FROM starter_organizations WHERE id = $id FOR UPDATE");
        check($gate->fetchColumn() === $id, 'Gate must lock the existing organization row');
        $gate->closeCursor();

        foreach ($requests as $index => [$method, $path, $input, $token]) {
            $clients[] = smsConnect($ports[$index], $method, '/saas' . $path, $input, sessionBearer($token));
            $deadline = microtime(true) + 3;
            do {
                // Match the target ID in the actual locking SELECT. Both distinct connections must be waiting at once.
                $waiting->execute(["%from %starter_organizations%where %id% = $id %for update%"]);
                if (count($waiting->fetchAll(PDO::FETCH_COLUMN)) === $index + 1) {
                    break;
                }
                check(microtime(true) < $deadline, "$method $path did not queue for organization $id (contender " . ($index + 1) . ')');
                usleep(1_000);
            } while (true);
        }
        // Start the second contender only after seeing the first queued. Both contend before the gate releases.
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

function issue31Races(SmsLoginFixture $qa, PDO $sql, array $ports, string $platform, int $actor): void
{
    $port = $ports[0];
    $large = issue31CreatePackage($port, $platform, 'Race two seats', 2);
    $smallName = 'Race one seat';
    $small = issue31CreatePackage($port, $platform, $smallName, 1);
    foreach ([true, false] as $switchFirst) {
        $ownerPhone = $switchFirst ? '13800138320' : '13800138321';
        $invitePhone = $switchFirst ? '13800138322' : '13800138323';
        $opened = d3Open($port, $platform, $large, 'Last seat fixture', $ownerPhone);
        $id = $opened['organization_id'];
        $owner = d3Join($port, $qa, $opened['link'], $ownerPhone, $id, 'owner');
        $before = issue31State($sql, $id);
        check($before['organization']['seat_limit'] === 2 && array_column($before['members'], 'role') === ['owner']
            && array_column($before['invitations'], 'status') === ['accepted'], 'The race starts with one free seat and a converted owner invitation');

        $switch = ['PATCH', issue31PackagePath($id), ['package_id' => $small], $platform];
        $invite = ['POST', "/organizations/$id/invitations", ['phone' => $invitePhone], $owner];
        [$first, $second] = issue31RaceRequests($sql, $id, $ports, $switchFirst ? [$switch, $invite] : [$invite, $switch]);
        $after = issue31State($sql, $id);
        if ($switchFirst) {
            $result = smsExpect($first, 200, 'success', 'Downgrade takes the last seat before invitation')['data'];
            smsExpect($second, 409, 'seat_limit_exceeded', 'Invitation cannot commit after the last seat is removed');
            issue31CheckSwitch($result, $before, $after, $actor, $small, $smallName, 1);
            echo "PASS issue #31 race downgrade first: 200 switch, 409 invite, one member in one seat\n";
        } else {
            $result = smsExpect($first, 201, 'success', 'Invitation reserves the last seat before downgrade')['data'];
            smsExpect($second, 409, 'seat_limit_exceeded', 'Downgrade cannot commit after the last seat is reserved');
            check($after['organization'] === $before['organization'] && $after['members'] === $before['members'],
                'Rejected downgrade preserves the original snapshot, dates and all members');
            check(count($after['invitations']) === 2 && $after['invitations'][0] === $before['invitations'][0],
                'Only the successful invitation is added to unchanged invitation history');
            $new = $after['invitations'][1];
            check($new['id'] === $result['invitation_id'] && $new['phone'] === '+86' . $invitePhone && $new['status'] === 'pending'
                && $new['reserved'] === 1 && $new['account_id'] === null && $new['role'] === 'member'
                && $new['expires_at'] > gmdate('Y-m-d H:i:s', SMS_TIME), 'One owner plus one live reservation fills the original two-seat snapshot');
            $events = array_slice($after['audits'], count($before['audits']));
            check(array_slice($after['audits'], 0, count($before['audits'])) === $before['audits']
                && array_column($events, 'action') === ['invitation_created']
                && json_decode($events[0]['metadata'], true, flags: JSON_THROW_ON_ERROR)['invitation_id'] === $new['id'],
                'Only the winning invitation is audited and the rejected switch leaves no audit');
            echo "PASS issue #31 race invite first: 201 invite, 409 switch, one member and one reservation in two seats\n";
        }
    }
}

$fixtures = new Fixtures();
try {
    $qa = prepareD2Host($fixtures, 'qa_packageswitch');
    $sql = $fixtures->database('qa_packageswitch', create: false);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');

    // Run one HTTP worker per listener to guarantee that two requests reach separate processes.
    $config = $fixtures->host . '/config/app.php';
    check(copy($config, $fixtures->host . '/config/app.race-original.inc'), 'Preserve disposable host configuration');
    check(file_put_contents($config, <<<'PHP'
        <?php
        $app = require __DIR__ . '/app.race-original.inc';
        $app['runtime_path'] .= '-' . getenv('WEBMAN_STARTER_HTTP_PORT');
        return $app;
        PHP) !== false, 'Isolate the runtime paths of the two disposable Webman instances');
    $ports = [$fixtures->port(), $fixtures->port()];
    foreach ($ports as $port) {
        $worker = $qa->start($port, 'issue31', D3_HOST_ENV + ['WEBMAN_STARTER_HTTP_WORKERS' => '1']);
        $fixtures->ready($worker, static fn (): bool => httpGet($port, '/saas/health')[0] === 200, 'Installed Webman package-switch worker');
    }
    $platform = d3Login($qa, $ports[0], PHONE, 'platform');
    $actor = d3Request($ports[0], 'GET', '/platform/me', access: $platform)['account_id'];
    issue31Switch($qa, $sql, $ports[0], $platform, $actor);
    issue31Races($qa, $sql, $ports, $platform, $actor);
    echo "PASS issue #31 through installed Webman, MySQL and Redis\n";
} finally {
    $fixtures->close();
}
