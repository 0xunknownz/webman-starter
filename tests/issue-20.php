<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

require __DIR__ . '/d2-fixtures.php';

const INVITATION_QA_PAGE = 'https://product.example.test/join?locale=zh';
const INVITATION_QA_PATH = '/saas/invitations';

function invite20Token(array $response): string
{
    $data = $response['data'];
    check(str_starts_with($data['link'], INVITATION_QA_PAGE . '#token='), 'Invitation link must use the configured page and a fragment');

    return substr((string) parse_url($data['link'], PHP_URL_FRAGMENT), strlen('token='));
}

function invite20Post(int $port, string $step, array $body, int $status = 200, string $code = 'success', array $headers = []): array
{
    return smsExpect(smsRequest($port, 'POST', INVITATION_QA_PATH . $step, $body, $headers), $status, $code, 'Invitation ' . $step);
}

function invite20Login(SmsLoginFixture $qa, int $port, string $phone, string $purpose = 'organization'): string
{
    return smsToken(smsLogin($port, $qa->issue($port, $phone), phone: $phone, purpose: $purpose));
}

function invite20Proof(SmsLoginFixture $qa, int $port, string $token, string $phone): array
{
    $body = ['token' => $token, 'phone' => $phone];
    $before = count($qa->messages());
    $send = invite20Post($port, '/sms/send', $body)['data'];
    $messages = $qa->messages();
    $message = $messages[$before];
    check(count($messages) === $before + 1 && $message['phone'] === '+86' . $phone && $message['purpose'] === 'invitation',
        'Invitation SMS must be submitted exactly once to the invitation destination');

    return invite20Post($port, '/sms/verify', $body + ['challenge_id' => $send['challenge_id'], 'code' => $message['code']])['data'];
}

function invite20Open(int $port, string $platform, int $package, string $name, string $phone, int $starts, int $ends): array
{
    return smsExpect(smsRequest($port, 'POST', '/saas/platform/organizations', [
        'name' => $name, 'phone' => $phone, 'package_id' => $package,
        'starts_at' => gmdate(DATE_ATOM, $starts), 'expires_at' => gmdate(DATE_ATOM, $ends),
    ], sessionBearer($platform)), 201, 'success', 'Open organization over HTTP');
}

function testInvitationAcceptance(Fixtures $fixture, SmsLoginFixture $qa, PDO $sql, int $port): void
{
    $qa->clock(SMS_TIME);
    $platform = invite20Login($qa, $port, PHONE, 'platform');
    $package = (int) $sql->query("SELECT id FROM starter_packages WHERE name = 'Issue 20'")->fetchColumn();
    $phone = '13800138200';
    $opening = invite20Open($port, $platform, $package, 'Future owner organization', $phone, SMS_TIME + 500, SMS_TIME + 86_400);
    $token = invite20Token($opening);
    $body = ['token' => $token, 'phone' => $phone];
    $before = $qa->messages();
    $preview = invite20Post($port, '/resolve', ['token' => $token])['data'];
    check($preview === [
        'organization_name' => 'Future owner organization', 'phone_masked' => '**********8200',
        'expires_at' => gmdate(DATE_ATOM, SMS_TIME + 604800), 'type' => 'owner',
    ], 'Preview returns only the four public fields');
    check(invite20Post($port, '/resolve', ['token' => $token])['data'] === $preview && $qa->messages() === $before,
        'Repeated previews do not send SMS');
    check((int) $sql->query('SELECT COUNT(*) FROM starter_accounts')->fetchColumn() === 1
        && (int) $sql->query('SELECT COUNT(*) FROM starter_organizations WHERE activated_at IS NOT NULL')->fetchColumn() === 0,
        'Link previews do not create an account or activate the organization');
    invite20Post($port, '/resolve', ['token' => substr($token, 0, -1) . ($token[-1] === 'a' ? 'b' : 'a')], 404, 'not_found');
    invite20Post($port, '/sms/send', ['token' => $token, 'phone' => '13800138201'], 409, 'invitation_invalid');
    invite20Post($port, '/accept', $body, 401, 'credential_expired');
    $proof = invite20Proof($qa, $port, $token, $phone);
    check($proof['expires_at'] === gmdate(DATE_ATOM, SMS_TIME + 600), 'Invitation proof expires 10 minutes after verification');
    sessionPlatform($port, $proof['credential'], 401, 'token_invalid');
    $accepted = invite20Post($port, '/accept', $body + ['credential' => $proof['credential'], 'organization_id' => -1])['data'];
    check($accepted['organization_id'] === $opening['data']['organization_id'] && $accepted['role'] === 'owner',
        'The invitation selects the organization and grants its first owner role');
    invite20Post($port, '/accept', $body + ['credential' => $proof['credential']], 409, 'invitation_invalid');
    $row = $sql->query('SELECT i.status, i.reserved, i.account_id, m.role, o.activated_at, o.subscription_starts_at '
        . 'FROM starter_invitations i JOIN starter_organizations o ON o.id=i.organization_id '
        . 'JOIN starter_memberships m ON m.organization_id=o.id AND m.account_id=i.account_id')->fetch(PDO::FETCH_ASSOC);
    check($row === [
        'status' => 'accepted', 'reserved' => 0, 'account_id' => $accepted['account_id'], 'role' => 'owner',
        'activated_at' => gmdate('Y-m-d H:i:s', SMS_TIME), 'subscription_starts_at' => gmdate('Y-m-d H:i:s', SMS_TIME + 500),
    ], 'First-owner acceptance converts one reservation and leaves the subscription window unchanged');

    $now = SMS_TIME + 500;
    $qa->clock($now);
    $owner = invite20Login($qa, $port, $phone);
    $org = $accepted['organization_id'];
    $other = invite20Open($port, $platform, $package, 'Other organization', $phone, $now, $now + 86_400);
    $otherToken = invite20Token($other);
    $otherId = $other['data']['organization_id'];
    $ownerProof = invite20Proof($qa, $port, $otherToken, $phone);
    $otherOwner = invite20Post($port, '/accept', ['token' => $otherToken, 'phone' => $phone] + $ownerProof)['data'];
    check($otherOwner['account_id'] === $accepted['account_id'], 'An existing owner can own another organization with the same account');

    testInvitationMemberRace($qa, $sql, $port, $owner, $org, $otherId, $now);
    testInvitationEligibility($qa, $sql, $port, $owner, $org, $now);
    testInvitationRebind($fixture, $qa, $sql, $port, $org, $now + 1_300);
}

function invite20Create(int $port, string $actor, int $org, string $phone): array
{
    return smsExpect(smsRequest($port, 'POST', "/saas/organizations/$org/invitations", ['phone' => $phone], sessionBearer($actor)),
        201, 'success', 'Create member invitation over HTTP');
}

function testInvitationMemberRace(SmsLoginFixture $qa, PDO $sql, int $port, string $owner, int $org, int $other, int $now): void
{
    $phone = '13800138201';
    $create = invite20Create($port, $owner, $org, $phone);
    $body = ['token' => invite20Token($create), 'phone' => $phone];
    $second = ['token' => invite20Token(invite20Create($port, $owner, $other, $phone)), 'phone' => $phone];
    $firstProof = invite20Proof($qa, $port, $body['token'], $phone);
    $qa->clock($now + 60);
    $secondProof = invite20Proof($qa, $port, $second['token'], $phone);
    invite20Post($port, '/accept', $second + $firstProof, 401, 'purpose_mismatch');
    smsExpect(smsRequest($port, 'GET', "/saas/organizations/$org/members", headers: sessionBearer($firstProof['credential'])),
        401, 'token_invalid', 'Invitation proof cannot open organization API');
    $responses = $qa->race($port, $now + 60, INVITATION_QA_PATH . '/accept', [$body + $firstProof, $second + $secondProof]);
    $first = smsExpect($responses[0], 200, 'success', 'First concurrent member acceptance')['data'];
    $second = smsExpect($responses[1], 200, 'success', 'Second concurrent member acceptance')['data'];
    check($first['account_id'] === $second['account_id'] && $first['role'] === 'member' && $second['role'] === 'member'
        && $first['organization_id'] === $org && $second['organization_id'] === $other,
        'Same-phone concurrent accepts create one global account with only the member role in both organizations');

    $invitee = '13800138202';
    $existing = rebindAccount($sql, $invitee);
    $row = invite20Create($port, $owner, $org, $invitee);
    $body = ['token' => invite20Token($row), 'phone' => $invitee];
    $access = invite20Login($qa, $port, $invitee);
    invite20Post($port, '/accept', $body, 401, 'credential_expired', sessionBearer($access));
    check(invite20Post($port, '/resolve', ['token' => $body['token']])['data']['type'] === 'member',
        'Logging in does not consume a member invitation');
    $proof = invite20Proof($qa, $port, $body['token'], $invitee);
    $responses = $qa->race($port, $now + 60, INVITATION_QA_PATH . '/accept', [$body + $proof, $body + $proof]);
    usort($responses, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
    $member = smsExpect($responses[0], 200, 'success', 'Only one response accepts the same invitation')['data'];
    smsExpect($responses[1], 409, 'invitation_invalid', 'Concurrent replay is rejected');
    check($member['account_id'] === $existing && $member['role'] === 'member', 'Accept reuses the existing account');

    $memberPath = "/saas/organizations/$org/members/$existing";
    smsExpect(smsRequest($port, 'PATCH', "$memberPath/role", ['role' => 'admin'], sessionBearer($owner)),
        200, 'success', 'Promote the existing member to inviter');
    $invitedByRemoved = '13800138209';
    $row = invite20Create($port, $access, $org, $invitedByRemoved);
    smsExpect(smsRequest($port, 'DELETE', $memberPath, headers: sessionBearer($owner)), 200, 'success', 'Remove the inviter');
    $body = ['token' => invite20Token($row), 'phone' => $invitedByRemoved];
    $proof = invite20Proof($qa, $port, $body['token'], $invitedByRemoved);
    check(invite20Post($port, '/accept', $body + $proof)['data']['role'] === 'member',
        'Removing the inviter leaves the organization invitation usable');

    $target = '13800138203';
    $row = invite20Create($port, $owner, $org, $target);
    $body = ['token' => invite20Token($row), 'phone' => $target];
    $proof = invite20Proof($qa, $port, $body['token'], $target);
    $sql->beginTransaction();
    $clients = [];
    try {
        $sql->query("SELECT id FROM starter_organizations WHERE id = $org FOR UPDATE")->fetchColumn();
        $clients[] = smsConnect($port, 'POST', INVITATION_QA_PATH . '/accept', $body + $proof);
        invite20WaitForLock($sql, 1);
        $clients[] = smsConnect($port, 'POST', "/saas/organizations/$org/invitations", ['phone' => $target], sessionBearer($owner));
        invite20WaitForLock($sql, 2);
        $sql->commit();
        smsExpect(smsReadResponse($clients[0]), 200, 'success', 'Acceptance racing creation succeeds');
        [$status, $error] = smsReadResponse($clients[1]);
        check($status === 409 && in_array($error['code'], ['member_already_exists', 'invitation_already_exists'], true),
            'Concurrent creation observes the reservation or the accepted member');
    } finally {
        if ($sql->inTransaction()) {
            $sql->rollBack();
        }
        foreach ($clients as $client) {
            fclose($client);
        }
    }
    $states = $sql->prepare('SELECT status, reserved FROM starter_invitations WHERE organization_id = ? AND phone = ?');
    $states->execute([$org, '+86' . $target]);
    check($states->fetchAll(PDO::FETCH_ASSOC) === [['status' => 'accepted', 'reserved' => 0]],
        'Creation racing acceptance leaves exactly one historical row and no additional reservation');
}

function invite20WaitForLock(PDO $sql, int $count): void
{
    $query = $sql->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() '
        . 'AND DB = DATABASE() AND (INFO LIKE ? OR INFO LIKE ?)');
    $deadline = microtime(true) + 3;
    do {
        $query->execute(['%from %starter_organizations%for update%', '%from %starter_accounts%for update%']);
        if ((int) $query->fetchColumn() >= $count) {
            return;
        }
        check(microtime(true) < $deadline, 'HTTP worker failed to reach its account or organization lock');
        usleep(1_000);
    } while (true);
}


function testInvitationEligibility(SmsLoginFixture $qa, PDO $sql, int $port, string $owner, int $org, int $now): void
{
    $path = "/saas/organizations/$org/invitations";
    foreach (['revoked' => '13800138204', 'replaced' => '13800138205'] as $change => $phone) {
        $created = invite20Create($port, $owner, $org, $phone);
        $resource = $path . '/' . $created['data']['invitation_id'];
        $body = ['token' => invite20Token($created), 'phone' => $phone];
        $proof = invite20Proof($qa, $port, $body['token'], $phone);
        if ($change === 'revoked') {
            smsExpect(smsRequest($port, 'DELETE', $resource, headers: sessionBearer($owner)), 200, 'success', 'Revoke invitation');
        } else {
            $replacement = smsExpect(smsRequest($port, 'POST', $resource . '/reissue', headers: sessionBearer($owner)),
                201, 'success', 'Replace invitation');
            invite20Post($port, '/accept', ['token' => invite20Token($replacement), 'phone' => $phone] + $proof, 401, 'purpose_mismatch');
            invite20Post($port, '/sms/send', ['token' => invite20Token($replacement), 'phone' => $phone], 429, 'sms_rate_limited');
        }
        invite20Post($port, '/accept', $body + $proof, 409, 'invitation_invalid');
        invite20Post($port, '/sms/send', $body, 409, 'invitation_invalid');
    }

    $phone = '13800138206';
    $body = ['token' => invite20Token(invite20Create($port, $owner, $org, $phone)), 'phone' => $phone];
    $proof = invite20Proof($qa, $port, $body['token'], $phone);
    $original = $sql->query("SELECT * FROM starter_organizations WHERE id = $org")->fetch(PDO::FETCH_ASSOC);
    $patch = static function (string $column, mixed $value) use ($sql, $org): void {
        $sql->prepare("UPDATE starter_organizations SET $column = ? WHERE id = ?")->execute([$value, $org]);
    };
    foreach ([
        ['archived_at', gmdate('Y-m-d H:i:s', $now), 403, 'organization_archived'],
        ['suspended_at', gmdate('Y-m-d H:i:s', $now), 403, 'organization_suspended'],
        ['activated_at', null, 403, 'organization_pending'],
        ['subscription_starts_at', gmdate('Y-m-d H:i:s', $now + 100), 403, 'subscription_inactive'],
        ['subscription_expires_at', gmdate('Y-m-d H:i:s', $now + 60), 403, 'subscription_expired'],
        ['seat_limit', 1, 409, 'seat_limit_exceeded'],
    ] as [$column, $value, $status, $code]) {
        $patch($column, $value);
        try {
            invite20Post($port, '/accept', $body + $proof, $status, $code);
        } finally {
            $patch($column, $original[$column]);
        }
    }
    $sql->exec("CREATE TRIGGER qa_invite_audit_failure BEFORE INSERT ON starter_audit_logs FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced acceptance audit failure'");
    try {
        invite20Post($port, '/accept', $body + $proof, 503, 'audit_unavailable');
    } finally {
        $sql->exec('DROP TRIGGER qa_invite_audit_failure');
    }
    check((int) $sql->query("SELECT COUNT(*) FROM starter_accounts WHERE phone = '+86$phone'")->fetchColumn() === 0,
        'An audit failure rolls back the account and membership');
    invite20Post($port, '/accept', $body + $proof);

    $phone = '13800138207';
    $created = invite20Create($port, $owner, $org, $phone);
    $body = ['token' => invite20Token($created), 'phone' => $phone];
    $proof = invite20Proof($qa, $port, $body['token'], $phone);
    $sql->prepare('UPDATE starter_invitations SET expires_at = ? WHERE id = ?')
        ->execute([gmdate('Y-m-d H:i:s', $now + 61), $created['data']['invitation_id']]);
    $sql->beginTransaction();
    $client = null;
    try {
        $sql->query("SELECT id FROM starter_organizations WHERE id = $org FOR UPDATE")->fetchColumn();
        $client = smsConnect($port, 'POST', INVITATION_QA_PATH . '/accept', $body + $proof);
        invite20WaitForLock($sql, 1);
        $qa->clock($now + 61);
        $sql->commit();
        smsExpect(smsReadResponse($client), 409, 'invitation_invalid', 'Invitation expires while the transaction waits on a SQL lock');
    } finally {
        if ($sql->inTransaction()) {
            $sql->rollBack();
        }
        if (is_resource($client)) {
            fclose($client);
        }
    }
    invite20Post($port, '/sms/send', $body, 409, 'invitation_invalid');

    $phone = '13800138208';
    $body = ['token' => invite20Token(invite20Create($port, $owner, $org, $phone)), 'phone' => $phone];
    $count = count($qa->messages());
    $send = invite20Post($port, '/sms/send', $body)['data'];
    $sms = $qa->messages()[$count];
    $attempt = $body + ['challenge_id' => $send['challenge_id'], 'code' => $sms['code']];
    for ($failure = 0; $failure < 4; $failure++) {
        invite20Post($port, '/sms/verify', array_replace($attempt, ['code' => wrongSmsCode($sms['code'])]),
            401, 'verification_code_invalid');
    }
    $proof = invite20Post($port, '/sms/verify', $attempt)['data'];
    invite20Post($port, '/sms/verify', $attempt, 401, 'credential_expired');
    $qa->clock($now + 660);
    $sql->beginTransaction();
    $client = null;
    try {
        $sql->query("SELECT id FROM starter_organizations WHERE id = $org FOR UPDATE")->fetchColumn();
        $client = smsConnect($port, 'POST', INVITATION_QA_PATH . '/accept', $body + $proof);
        invite20WaitForLock($sql, 1);
        $qa->clock($now + 661);
        $sql->commit();
        smsExpect(smsReadResponse($client), 401, 'credential_expired', 'Proof expires while the transaction waits on a SQL lock');
    } finally {
        if ($sql->inTransaction()) {
            $sql->rollBack();
        }
        if (is_resource($client)) {
            fclose($client);
        }
    }
    check(invite20Post($port, '/resolve', ['token' => $body['token']])['data']['type'] === 'member',
        'An expired proof does not invalidate the invitation');
}

function testInvitationRebind(Fixtures $fixture, SmsLoginFixture $qa, PDO $sql, int $port, int $org, int $now): void
{
    $qa->clock($now);
    $owner = invite20Login($qa, $port, '13800138200');
    $platform = invite20Login($qa, $port, PHONE, 'platform');
    $case = "$fixture->dir/invite-recovery-case";
    $accountId = $sql->prepare('SELECT id FROM starter_accounts WHERE phone = ?');
    foreach ([
        'normal' => ['13800138211', '13900139211'],
        'email' => ['13800138212', '13900139212'],
        'manual' => ['13800138213', '13900139213'],
        'offline' => [PHONE, OPERATOR_NEW_PHONE],
    ] as $mode => [$old, $new]) {
        if ($mode !== 'offline') {
            rebindAccount($sql, $old);
        }
        $accountId->execute(['+86' . $old]);
        $account = (int) $accountId->fetchColumn();
        $created = invite20Create($port, $owner, $org, $old);
        $body = ['token' => invite20Token($created), 'phone' => $old];
        $proof = invite20Proof($qa, $port, $body['token'], $old);
        $id = $created['data']['invitation_id'];
        $dbInvite = static fn (): array => $sql->query("SELECT status, reserved, account_id FROM starter_invitations WHERE id = $id")
            ->fetch(PDO::FETCH_ASSOC);
        check($dbInvite() === ['status' => 'pending', 'reserved' => 1, 'account_id' => null],
            "$mode case begins with one reserved invitation without an account ID");
        $before = invite20Seats($sql, $org, $now);
        if ($mode === 'normal') {
            $member = invite20Login($qa, $port, $old);
            $flow = rebindProof($qa, $port, $member, $old, $new);
            phoneChangeRequest($port, $member, "$flow/complete");
        } elseif ($mode === 'email') {
            $email = 'acceptance-recovery@example.test';
            $sql->prepare('UPDATE starter_accounts SET email = ?, email_verified_at = ? WHERE id = ?')
                ->execute([$email, gmdate('Y-m-d H:i:s', $now), $account]);
            $challenge = recoveryChallenge($qa, $port, $email);
            $recovery = recoveryRequest($port, '/verify', $challenge + ['new_phone' => $new])['data'];
            $flow = $recovery['flow_id'];
            $scope = ['recovery_token' => $recovery['recovery_token']];
            recoveryRequest($port, "/$flow/new/verify", $scope + recoverySms($qa, $port, $flow, $scope, $new));
            recoveryRequest($port, "/$flow/complete", $scope);
        } elseif ($mode === 'manual') {
            $accountId->execute(['+86' . PHONE]);
            $actor = (int) $accountId->fetchColumn();
            file_put_contents($case, json_encode([
                'channel' => 'registered_contact', 'reference' => 'CASE-ALPHA', 'reason' => 'lost_phone',
                'account_id' => $account, 'new_phone' => '+86' . $new, 'actor' => $actor,
            ], JSON_THROW_ON_ERROR));
            $flow = '/' . operatorRecoveryRequest($port, $platform, data: [
                'account_id' => $account, 'new_phone' => $new, 'reason' => 'lost_phone',
                'evidence' => ['channel' => 'registered_contact', 'reference' => 'CASE-ALPHA'],
            ], status: 201)['flow_id'];
            $count = count($qa->messages());
            $challenge = operatorRecoveryRequest($port, $platform, "$flow/new/send");
            $messages = $qa->messages();
            check(count($messages) === $count + 1 && $messages[$count]['phone'] === '+86' . $new,
                'Manual recovery submits an SMS to the new phone');
            operatorRecoveryRequest($port, $platform, "$flow/new/verify", $challenge + ['code' => $messages[$count]['code']]);
            operatorRecoveryRequest($port, $platform, "$flow/complete");
        } else {
            approveOperatorRecovery($case, $account, 'uid:' . posix_geteuid(), 'authorized_channel');
            $cli = $fixture->start([
                PHP_BINARY, 'vendor/bin/webman-starter', 'platform:recover', $new, 'lost_phone', 'authorized_channel', 'CASE-ALPHA',
            ], $fixture->host, [
                'QA_OPERATOR_RECOVERY_CASE' => $case, 'STARTER_OPERATOR_RECOVERY_CLI_ENABLED' => '1',
                'STARTER_AUTH_ENABLED' => '1', 'STARTER_AUTH_SECRET' => str_repeat('qa01-sms-fixture-', 3),
                'STARTER_REDIS_PREFIX' => 'qa01:invitation-acceptance', 'QA_SMS_STATE' => $qa->stateDirectory(),
            ], pipeInput: true);
            try {
                $cli->writeInput(awaitOfflineRecoverySms($fixture, $qa, $cli) . "\n");
                cliResult($cli->result(), true, 'platform_admin_recovered');
            } finally {
                $cli->stop();
            }
        }
        invite20Post($port, '/resolve', ['token' => $body['token']], 409, 'invitation_invalid');
        invite20Post($port, '/accept', $body + $proof, 409, 'invitation_invalid');
        check($dbInvite() === ['status' => 'revoked', 'reserved' => 0, 'account_id' => null]
            && invite20Seats($sql, $org, $now) === $before - 1, "$mode recovery revokes the old link and releases exactly one reservation");
        $accountId->execute(['+86' . $new]);
        check((int) $accountId->fetchColumn() === $account, "$mode recovery preserves the account ID");
    }
}

function invite20Seats(PDO $sql, int $org, int $now): int
{
    $query = $sql->prepare("SELECT (SELECT COUNT(*) FROM starter_memberships WHERE organization_id = ?) +
        (SELECT COUNT(*) FROM starter_invitations WHERE organization_id = ? AND status = 'pending' AND reserved = 1 AND expires_at > ?)");
    $query->execute([$org, $org, gmdate('Y-m-d H:i:s', $now)]);

    return (int) $query->fetchColumn();
}

$fixture = new Fixtures();
try {
    $qa = prepareD2Host($fixture, 'qa_invitationacceptance');
    $sql = $fixture->database('qa_invitationacceptance', create: false);
    cliResult(init($fixture, PHONE)->result(), true, 'platform_admin_initialized');
    $sql->exec("INSERT INTO starter_packages (name, seat_limit) VALUES ('Issue 20', 12)");
    $qa->outcome('accepted');
    operatorRecoveryFixture($fixture);
    $qa->withHost('invitation-acceptance', static fn (int $port) => testInvitationAcceptance($fixture, $qa, $sql, $port), [
        'STARTER_INVITATION_PAGE_URL' => INVITATION_QA_PAGE,
        'WEBMAN_STARTER_HTTP_WORKERS' => '2',
        'QA_OPERATOR_RECOVERY_CASE' => "$fixture->dir/invite-recovery-case",
    ]);
    echo "PASS invitation acceptance through Webman HTTP, MySQL and Redis\n";
} finally {
    $fixture->close();
}
