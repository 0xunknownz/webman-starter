<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

const REBIND_PATH = '/saas/account/phone/change';
const REBIND_NEW = '13900139001';
const REBIND_OTHER = '13900139002';

function phoneChangeRequest(
    int $port,
    ?string $access,
    string $step = '',
    array $data = [],
    int $status = 200,
    string $code = 'success',
): array {
    return smsExpect(smsRequest($port, 'POST', REBIND_PATH . $step, $data,
        $access === null ? [] : sessionBearer($access)), $status, $code, 'Phone change ' . $step)['data'] ?? [];
}

function rebindIssue(SmsLoginFixture $qa, int $port, string $access, string $phone, string $step = '', array $data = []): array
{
    $before = count($qa->messages());
    $response = phoneChangeRequest($port, $access, $step, $data);
    $messages = $qa->messages();
    check(count($messages) === $before + 1, 'Rebind did not submit exactly one SMS');
    check($messages[$before]['phone'] === '+86' . $phone && $messages[$before]['purpose'] === 'rebind',
        'Rebind used the wrong purpose or destination');
    check(($response['accepted'] ?? null) === true && preg_match('/\A[0-9a-f]{32}\z/', $response['challenge_id'] ?? '') === 1,
        'Rebind send did not return the accepted challenge response');

    return $response + ['code' => $messages[$before]['code']];
}

function rebindStart(SmsLoginFixture $qa, int $port, string $access, string $old, string $new): array
{
    $challenge = rebindIssue($qa, $port, $access, $old, data: ['new_phone' => $new, 'account_id' => -1]);
    check(preg_match('/\A[0-9a-f]{32}\z/', $challenge['flow_id'] ?? '') === 1, 'Expected an opaque phone change flow');

    return ['/' . $challenge['flow_id'], $challenge];
}

function rebindProof(SmsLoginFixture $qa, int $port, string $access, string $source, string $destination): string
{
    [$flow, $old] = rebindStart($qa, $port, $access, $source, $destination);
    phoneChangeRequest($port, $access, "$flow/old/verify", $old);
    $new = rebindIssue($qa, $port, $access, $destination, "$flow/new/send");
    phoneChangeRequest($port, $access, "$flow/new/verify", $new);

    return $flow;
}

function rebindAccount(PDO $db, string $phone): int
{
    $db->prepare('INSERT INTO starter_accounts (phone, created_at, updated_at) VALUES (?, NOW(), NOW())')->execute(['+86' . $phone]);

    return (int) $db->lastInsertId();
}

/** @return array{array<int,int>,int} */
function rebindInvitations(PDO $db, int $account, string $phone): array
{
    $expiry = gmdate('Y-m-d H:i:s', SMS_TIME + 7 * 86400);
    $expired = gmdate('Y-m-d H:i:s', SMS_TIME - 1);
    $createOrg = $db->prepare('INSERT INTO starter_organizations (name, activated_at, subscription_expires_at) VALUES (?, NOW(), ?)');
    $createInvite = $db->prepare('INSERT INTO starter_invitations (organization_id, account_id, phone, expires_at, status, reserved)
        VALUES (?, ?, ?, ?, ?, ?)');
    $ids = [];
    $firstOrg = null;
    foreach ([['pending', true, $account, $expiry], ['pending', true, null, $expiry],
        ['pending', false, null, $expired], ['accepted', false, $account, $expiry], ['revoked', false, null, $expiry],
    ] as [$status, $reserved, $linked, $expires]) {
        $createOrg->execute(['Fixture organization ' . count($ids), $expiry]);
        $org = (int) $db->lastInsertId();
        $firstOrg ??= $org;
        $createInvite->execute([$org, $linked, '+86' . $phone, $expires, $status, (int) $reserved]);
        $ids[] = (int) $db->lastInsertId();
        if ($status === 'accepted') {
            $db->prepare("INSERT INTO starter_memberships (organization_id, account_id, role) VALUES (?, ?, 'owner')")
                ->execute([$org, $account]);
        }
    }
    $createInvite->execute([$firstOrg, null, '+86' . REBIND_OTHER, $expiry, 'pending', 1]);

    return [$ids, $firstOrg];
}

function testPhoneRebind(Fixtures $fixtures, SmsLoginFixture $qa): void
{
    $db = $fixtures->database('qa_rebind');
    migrate($fixtures);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $account = (int) $db->query("SELECT account_id FROM starter_platform_admins WHERE status = 'active'")->fetchColumn();
    rebindAccount($db, SMS_MEMBER);
    rebindInvitations($db, $account, PHONE);
    $qa->clock(SMS_TIME);
    $qa->outcome('accepted');
    $qa->withHost('rebind-normal', static function (int $port) use ($qa, $db, $account): void {
        phoneChangeRequest($port, null, data: ['new_phone' => REBIND_NEW], status: 401, code: 'unauthenticated');
        $org = sessionTokens(smsLogin($port, $qa->issue($port), purpose: 'organization'));
        $other = smsToken(smsLogin($port, $qa->issue($port, SMS_MEMBER), phone: SMS_MEMBER, purpose: 'organization'));
        $qa->clock(SMS_TIME + 60);
        $platform = sessionTokens(smsLogin($port, $qa->issue($port)));
        $qa->clock(SMS_TIME + 120);
        $oldLogin = $qa->issue($port);
        $access = $org['access_token'];
        $before = count($qa->messages());
        phoneChangeRequest($port, $access, data: ['new_phone' => '+12025550123'], status: 422, code: 'phone_invalid');
        phoneChangeRequest($port, $access, data: ['new_phone' => '+86' . PHONE], status: 422, code: 'validation_failed');
        phoneChangeRequest($port, $access, data: ['new_phone' => SMS_MEMBER], status: 409, code: 'phone_already_used');
        check(count($qa->messages()) === $before, 'Validation failure triggered an SMS');
        [$flow, $old] = rebindStart($qa, $port, $access, PHONE, REBIND_NEW);
        foreach (['old/send', 'old/verify', 'new/send', 'new/verify', 'complete'] as $step) {
            phoneChangeRequest($port, null, "$flow/$step", $old, 401, 'unauthenticated');
        }
        phoneChangeRequest($port, $other, "$flow/old/verify", $old, 404, 'not_found');
        phoneChangeRequest($port, $access, '/invalid/complete', status: 404, code: 'not_found');
        phoneChangeRequest($port, $access, "$flow/new/send", status: 401, code: 'credential_expired');
        phoneChangeRequest($port, $access, "$flow/complete", status: 401, code: 'credential_expired');
        phoneChangeRequest($port, $access, "$flow/old/verify", $oldLogin, 401, 'purpose_mismatch');
        $wrong = array_replace($old, ['code' => wrongSmsCode($old['code'])]);
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            phoneChangeRequest($port, $access, "$flow/old/verify", $wrong, 401, 'verification_code_invalid');
        }
        check(phoneChangeRequest($port, $access, "$flow/old/verify", $old) === ['verified_until' => gmdate(DATE_ATOM, SMS_TIME + 720)],
            'Old proof deadline differs from verification time plus ten minutes');
        phoneChangeRequest($port, $access, "$flow/old/verify", $old, 401, 'credential_expired');
        phoneChangeRequest($port, $access, "$flow/complete", status: 401, code: 'credential_expired');

        $blocked = rebindAccount($db, REBIND_NEW);
        $before = count($qa->messages());
        phoneChangeRequest($port, $access, "$flow/new/send", status: 409, code: 'phone_already_used');
        check(count($qa->messages()) === $before, 'An occupied new phone reached the SMS sender');
        $db->exec("DELETE FROM starter_accounts WHERE id = $blocked");
        $new = rebindIssue($qa, $port, $access, REBIND_NEW, "$flow/new/send");
        phoneChangeRequest($port, $access, "$flow/new/verify", $new);
        phoneChangeRequest($port, $access, "$flow/new/verify", $new, 401, 'credential_expired');

        $db->prepare('UPDATE starter_accounts SET retained_session_id = ?, retained_session_epoch = 0, retained_at_epoch = 0 WHERE id = ?')
            ->execute([str_repeat('a', 32), $account]);
        $db->exec("CREATE TRIGGER qa_rebind_reject_audit BEFORE INSERT ON starter_audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa01 forced rebind audit failure'");
        $snapshot = snapshot($db);
        try {
            phoneChangeRequest($port, $access, "$flow/complete", status: 503, code: 'audit_unavailable');
            check(snapshot($db) === $snapshot, 'Audit failure left a partial phone, proof, invitation, session or membership change');
        } finally {
            $db->exec('DROP TRIGGER qa_rebind_reject_audit');
        }

        $membership = $db->query('SELECT * FROM starter_memberships')->fetchAll(PDO::FETCH_ASSOC);
        check(phoneChangeRequest($port, $access, "$flow/complete") === ['changed' => true], 'Completion returned unexpected data');
        phoneChangeRequest($port, $access, "$flow/complete", status: 401, code: 'token_invalid');
        foreach ([$org, $platform] as $session) {
            smsExpect(smsRequest($port, 'GET', '/saas/account/profile', headers: sessionBearer($session['access_token'])),
                401, 'token_invalid', 'Old access after rebind');
            sessionRefresh($port, $session['refresh_token'], 401, 'token_invalid');
        }
        smsLogin($port, $oldLogin, 401, 'purpose_mismatch');
        $qa->clock(SMS_TIME + 180);
        $before = count($qa->messages());
        smsSend($port, PHONE);
        check(count($qa->messages()) === $before, 'Old unbound phone remained eligible for login SMS');
        $newAccess = smsToken(smsLogin($port, $qa->issue($port, REBIND_NEW), phone: REBIND_NEW));
        $me = smsExpect(smsRequest($port, 'GET', PLATFORM_ME, headers: sessionBearer($newAccess)), 200, 'success', 'New phone login');
        check($me['data']['account_id'] === $account, 'Rebind changed account ID or platform qualification');
        phoneChangeRequest($port, $newAccess, "$flow/complete", status: 404, code: 'not_found');
        check($db->query('SELECT * FROM starter_memberships')->fetchAll(PDO::FETCH_ASSOC) === $membership,
            'Rebind changed an accepted membership or role');
        $rows = $db->query('SELECT status, reserved FROM starter_invitations ORDER BY id')->fetchAll(PDO::FETCH_NUM);
        check($rows === [['revoked', 0], ['revoked', 0], ['revoked', 0], ['accepted', 0], ['revoked', 0], ['pending', 1]],
            'Rebind did not revoke every old-phone pending invitation or changed a protected invitation');
        $row = $db->query("SELECT phone_epoch, session_epoch, retained_session_id, retained_session_epoch, retained_at_epoch
            FROM starter_accounts WHERE id = $account")->fetch(PDO::FETCH_ASSOC);
        check((int) $row['phone_epoch'] === 1 && (int) $row['session_epoch'] === 1, 'Phone and session epochs did not increment once');
        check($row['retained_session_id'] === null && $row['retained_session_epoch'] === null && $row['retained_at_epoch'] === null,
            'Phone change did not clear retained session state');
        $audits = $db->query("SELECT actor_account_id, target_account_id, metadata FROM starter_audit_logs WHERE action = 'phone_changed'")
            ->fetchAll(PDO::FETCH_ASSOC);
        check(count($audits) === 1 && (int) $audits[0]['actor_account_id'] === $account
            && (int) $audits[0]['target_account_id'] === $account, 'Missing or repeated rebind audit identity');
        $audit = json_decode($audits[0]['metadata'], true, flags: JSON_THROW_ON_ERROR);
        ksort($audit);
        check($audit === ['method' => 'normal', 'new_phone_masked' => '**********9001', 'old_phone_masked' => '**********8000',
            'result' => 'success'], 'Rebind audit did not contain exactly the redacted change result');
    });
    echo "PASS authenticated phone change, quota order, OTP binding, atomic invitation cleanup and audit rollback\n";

    testRebindDeadlines($fixtures, $qa);
    testRebindEmail($fixtures, $qa);
    testRebindRace($fixtures, $qa);
    testRebindInviteRace($fixtures, $qa);
    testRebindInvalidatesDestinationCredentials($fixtures, $qa);
}

function testRebindDeadlines(Fixtures $fixtures, SmsLoginFixture $qa): void
{
    $db = $fixtures->database('qa_rebinddeadlines');
    migrate($fixtures);
    $account = rebindAccount($db, PHONE);
    $qa->clock(SMS_TIME);
    $qa->withHost('rebind-deadlines', static function (int $port) use ($qa, $db, $account): void {
        $session = sessionTokens(smsLogin($port, $qa->issue($port), purpose: 'organization'));
        $access = $session['access_token'];
        [$flow, $old] = rebindStart($qa, $port, $access, PHONE, REBIND_NEW);
        $qa->clock(SMS_TIME + 60);
        [$otherFlow, $otherOld] = rebindStart($qa, $port, $access, PHONE, REBIND_OTHER);
        phoneChangeRequest($port, $access, "$otherFlow/old/verify", $old, 401, 'purpose_mismatch');
        phoneChangeRequest($port, $access, "$flow/old/verify", $otherOld, 401, 'purpose_mismatch');
        $qa->clock(SMS_TIME + 300);
        phoneChangeRequest($port, $access, "$flow/old/verify", $old, 401, 'credential_expired');
        $old = rebindIssue($qa, $port, $access, PHONE, "$flow/old/send");
        $wrongOld = array_replace($old, ['code' => wrongSmsCode($old['code'])]);
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            phoneChangeRequest($port, $access, "$flow/old/verify", $wrongOld, 401, 'verification_code_invalid');
        }
        phoneChangeRequest($port, $access, "$flow/old/verify", $wrongOld, 401, 'verification_attempts_exceeded');
        phoneChangeRequest($port, $access, "$flow/old/verify", $old, 401, 'credential_expired');

        $qa->clock(SMS_TIME + 360);
        $old = rebindIssue($qa, $port, $access, PHONE, "$flow/old/send");
        check(phoneChangeRequest($port, $access, "$flow/old/verify", $old)['verified_until'] === gmdate(DATE_ATOM, SMS_TIME + 960),
            'Old proof did not get its own deadline');
        $new = rebindIssue($qa, $port, $access, REBIND_NEW, "$flow/new/send");
        phoneChangeRequest($port, $access, "$flow/old/verify", $new, 401, 'purpose_mismatch');
        $wrongNew = array_replace($new, ['code' => wrongSmsCode($new['code'])]);
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            phoneChangeRequest($port, $access, "$flow/new/verify", $wrongNew, 401, 'verification_code_invalid');
        }
        phoneChangeRequest($port, $access, "$flow/new/verify", $wrongNew, 401, 'verification_attempts_exceeded');
        phoneChangeRequest($port, $access, "$flow/new/verify", $new, 401, 'credential_expired');

        $qa->clock(SMS_TIME + 419);
        phoneChangeRequest($port, $access, "$flow/new/send", status: 429, code: 'sms_rate_limited');
        $qa->clock(SMS_TIME + 420);
        $qa->outcome('failed');
        phoneChangeRequest($port, $access, "$flow/new/send", status: 502, code: 'sms_submission_failed');
        $qa->clock(SMS_TIME + 480);
        $qa->outcome('timeout');
        phoneChangeRequest($port, $access, "$flow/new/send", status: 504, code: 'sms_submission_timeout');
        $qa->outcome('accepted');
        $qa->clock(SMS_TIME + 540);
        $new = rebindIssue($qa, $port, $access, REBIND_NEW, "$flow/new/send");
        $qa->clock(SMS_TIME + 550);
        $wrongNew = array_replace($new, ['code' => wrongSmsCode($new['code'])]);
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            phoneChangeRequest($port, $access, "$flow/new/verify", $wrongNew, 401, 'verification_code_invalid');
        }
        check(phoneChangeRequest($port, $access, "$flow/new/verify", $new)['verified_until'] === gmdate(DATE_ATOM, SMS_TIME + 1150),
            'New proof did not get its own deadline after four wrong attempts');

        $qa->clock(SMS_TIME + 899);
        $access = smsToken(sessionRefresh($port, $session['refresh_token']));
        $qa->clock(SMS_TIME + 959);
        $old = rebindIssue($qa, $port, $access, PHONE, "$flow/old/send");
        $new = rebindIssue($qa, $port, $access, REBIND_NEW, "$flow/new/send");
        $qa->clock(SMS_TIME + 960);
        phoneChangeRequest($port, $access, "$flow/new/send", status: 401, code: 'credential_expired');
        phoneChangeRequest($port, $access, "$flow/complete", status: 401, code: 'credential_expired');
        check(phoneChangeRequest($port, $access, "$flow/old/verify", $old)['verified_until'] === gmdate(DATE_ATOM, SMS_TIME + 1560),
            'Refreshing the old proof did not set a new deadline');
        $qa->clock(SMS_TIME + 1150);
        phoneChangeRequest($port, $access, "$flow/complete", status: 401, code: 'credential_expired');
        check(phoneChangeRequest($port, $access, "$flow/new/verify", $new)['verified_until'] === gmdate(DATE_ATOM, SMS_TIME + 1750),
            'Reissuing a code should renew a proof only after successful verification');
        $qa->clock(SMS_TIME + 1559);
        check(phoneChangeRequest($port, $access, "$flow/complete") === ['changed' => true],
            'A valid proof should still succeed one second before its deadline after OTP expiry');
        $events = $db->query("SELECT action, metadata FROM starter_audit_logs WHERE target_account_id = $account AND action LIKE 'sms_%'")
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($events as $event) {
            check(!str_contains($event['metadata'], PHONE) && !str_contains($event['metadata'], REBIND_NEW),
                'SMS audit leaked an unmasked phone');
        }
        check(count(array_filter($events, static fn (array $event): bool => $event['action'] === 'sms_rate_limited')) === 1,
            'Rebind rate limit was not audited exactly once');
    });
    echo "PASS rebind five-minute OTP and independent ten-minute proof boundaries, attempts, resend, and provider outcomes\n";
}

function testRebindEmail(Fixtures $fixtures, SmsLoginFixture $qa): void
{
    $db = $fixtures->database('qa_rebindemail');
    migrate($fixtures);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $account = (int) $db->query('SELECT account_id FROM starter_platform_admins')->fetchColumn();
    $qa->clock(SMS_TIME);
    $qa->withHost('rebind-email', static function (int $port) use ($qa, $account): void {
        $access = smsToken(smsLogin($port, $qa->issue($port)));
        $email = 'rebind@example.test';
        $password = 'rebind-only-fixture-password';
        $challenge = emailChallenge($qa, $port, $access, $email);
        emailBind($port, $access, $email, $challenge);
        emailPassword($port, $access, $password);
        $flow = rebindProof($qa, $port, $access, PHONE, REBIND_NEW);
        phoneChangeRequest($port, $access, "$flow/complete");
        $fresh = smsToken(emailLogin($port, $email, $password)['data']);
        $me = smsExpect(smsRequest($port, 'GET', PLATFORM_ME, headers: sessionBearer($fresh)), 200, 'success', 'Email after rebind');
        check($me['data']['account_id'] === $account, 'Email login after rebind did not preserve account identity');
    });
    echo "PASS previously enabled email/password works immediately after phone change\n";
}

/** @param list<array{string,array,list<string>}> $requests */
function rebindParallel(SmsLoginFixture $qa, int $port, int $when, array $requests): array
{
    $gate = 'rebind-race-' . bin2hex(random_bytes(8));
    $qa->clock($when, $gate);
    $clients = [];
    try {
        foreach ($requests as [$path, $body, $headers]) {
            $clients[] = smsConnect($port, 'POST', $path, $body, $headers);
        }
        $responses = array_map(smsReadResponse(...), $clients);
        check(count(glob($qa->stateDirectory() . "/$gate-*")) === 2, 'Phone change race did not use two Webman workers');

        return $responses;
    } finally {
        foreach ($clients as $client) {
            fclose($client);
        }
        $qa->clock($when);
    }
}

function testRebindRace(Fixtures $fixtures, SmsLoginFixture $qa): void
{
    $db = $fixtures->database('qa_rebindrace');
    migrate($fixtures);
    rebindAccount($db, PHONE);
    rebindAccount($db, SMS_MEMBER);
    $qa->clock(SMS_TIME);
    $qa->withHost('rebind-race', static function (int $port) use ($qa, $db): void {
        $left = sessionTokens(smsLogin($port, $qa->issue($port), purpose: 'organization'));
        $right = sessionTokens(smsLogin($port, $qa->issue($port, SMS_MEMBER), phone: SMS_MEMBER, purpose: 'organization'));
        $flowA = rebindProof($qa, $port, $left['access_token'], PHONE, REBIND_NEW);
        $qa->clock(SMS_TIME + 60);
        $flowB = rebindProof($qa, $port, $right['access_token'], SMS_MEMBER, REBIND_NEW);
        $results = rebindParallel($qa, $port, SMS_TIME + 61, [
            [REBIND_PATH . "$flowA/complete", [], sessionBearer($left['access_token'])],
            [REBIND_PATH . "$flowB/complete", [], sessionBearer($right['access_token'])],
        ]);
        $winner = $results[0][0] === 200 ? 0 : 1;
        smsExpect($results[$winner], 200, 'success', 'Shared telephone winner');
        smsExpect($results[1 - $winner], 409, 'phone_already_used', 'Shared telephone loser');
        $changed = $db->query("SELECT id, phone_epoch, session_epoch FROM starter_accounts WHERE phone = '+86" . REBIND_NEW . "'")
            ->fetchAll(PDO::FETCH_ASSOC);
        check(count($changed) === 1 && (int) $changed[0]['phone_epoch'] === 1 && (int) $changed[0]['session_epoch'] === 1,
            'Shared telephone race did not commit exactly once');
        $audits = $db->query("SELECT target_account_id FROM starter_audit_logs WHERE action = 'phone_changed'")->fetchAll(PDO::FETCH_COLUMN);
        check($audits === [$changed[0]['id']], 'Shared telephone race did not leave exactly one matching audit');

        $loser = [$right, $left][$winner];
        $loserPhone = [SMS_MEMBER, PHONE][$winner];
        $qa->clock(SMS_TIME + 120);
        $refreshFlow = rebindProof($qa, $port, $loser['access_token'], $loserPhone, REBIND_OTHER);
        $results = rebindParallel($qa, $port, SMS_TIME + 121, [
            [REBIND_PATH . "$refreshFlow/complete", [], sessionBearer($loser['access_token'])],
            [SESSION_REFRESH, ['refresh_token' => $loser['refresh_token']], []],
        ]);
        smsExpect($results[0], 200, 'success', 'Phone change racing refresh');
        if ($results[1][0] === 200) {
            $tokens = sessionTokens(smsExpect($results[1], 200, 'success', 'Refresh preceding phone change')['data']);
            smsExpect(smsRequest($port, 'GET', '/saas/account/profile', headers: sessionBearer($tokens['access_token'])),
                401, 'token_invalid', 'Access rotated during rebind');
            sessionRefresh($port, $tokens['refresh_token'], 401, 'token_invalid');
        } else {
            smsExpect($results[1], 401, 'token_invalid', 'Refresh after phone change');
        }
        sessionRefresh($port, $loser['refresh_token'], 401, 'token_invalid');
    });
    echo "PASS parallel MySQL phone ownership commits once and a concurrent refresh cannot revive the old session\n";
}

function testRebindInviteRace(Fixtures $fixtures, SmsLoginFixture $qa): void
{
    $db = $fixtures->database('qa_rebindinvite');
    migrate($fixtures);
    $account = rebindAccount($db, PHONE);
    [[$invite, $otherInvite], $org] = rebindInvitations($db, $account, PHONE);
    $qa->clock(SMS_TIME);
    $qa->withHost('rebind-invite-race', static function (int $port) use ($fixtures, $qa, $db, $account, $invite, $otherInvite, $org): void {
        $access = smsToken(smsLogin($port, $qa->issue($port), purpose: 'organization'));
        $flow = rebindProof($qa, $port, $access, PHONE, REBIND_NEW);
        $client = null;
        $db->beginTransaction();
        try {
            // D2 acceptance must lock the canonical phone before the invitation row and recheck both.
            $lock = $db->prepare('SELECT id FROM starter_accounts WHERE phone = ? FOR UPDATE');
            $lock->execute(['+86' . PHONE]);
            check((int) $lock->fetchColumn() === $account, 'Fixture did not lock the old phone account');
            $lock->closeCursor();
            $pending = $db->query("SELECT status FROM starter_invitations WHERE id = $invite FOR UPDATE")->fetchColumn();
            check($pending === 'pending', 'Fixture invitation did not start pending');
            clearstatcache(true, $fixtures->sqlLog);
            $offset = filesize($fixtures->sqlLog);
            check($offset !== false, 'Could not locate the invitation race in the query log');
            $client = smsConnect($port, 'POST', REBIND_PATH . "$flow/complete", [], sessionBearer($access));
            $deadline = microtime(true) + 2;
            do {
                $log = file_get_contents($fixtures->sqlLog, false, null, $offset);
                check($log !== false, 'Could not read the rebind race in the MySQL log');
                if (preg_match('/from \x60starter_accounts\x60[^\r\n]*for update/i', $log) === 1) {
                    break;
                }
                check(microtime(true) < $deadline, 'Rebind did not reach the account lock held by acceptance');
                usleep(1_000);
            } while (true);

            $db->exec("UPDATE starter_invitations SET status = 'accepted', reserved = 0 WHERE id = $invite");
            $db->exec("INSERT INTO starter_memberships (organization_id, account_id, role) VALUES ($org, $account, 'member')");
            $db->commit();
            smsExpect(smsReadResponse($client), 200, 'success', 'Rebind after overlapping acceptance');
            check($db->query("SELECT status FROM starter_invitations WHERE id = $invite")->fetchColumn() === 'accepted',
                'Rebind revoked an accepted invitation');
            check($db->query("SELECT status FROM starter_invitations WHERE id = $otherInvite")->fetchColumn() === 'revoked',
                'Rebind did not revoke the still-pending invitation');
            check($db->query("SELECT role FROM starter_memberships WHERE organization_id = $org AND account_id = $account")
                ->fetchColumn() === 'member', 'Rebind removed the membership committed by acceptance');

            // The inverse order sees the key absent and the pending invitation already revoked.
            $db->beginTransaction();
            $lock->execute(['+86' . PHONE]);
            check($lock->fetchColumn() === false, 'Old phone still identifies its previous account');
            $lock->closeCursor();
            $row = $db->query("SELECT status, reserved FROM starter_invitations WHERE id = $otherInvite FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            check($row === ['status' => 'revoked', 'reserved' => 0], 'Acceptance after rebind could still consume the reservation');
            $db->rollBack();
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (is_resource($client)) {
                fclose($client);
            }
        }
    });
    echo "PASS rebind serializes against a real invitation acceptance transaction and preserves accepted membership\n";
}

function testRebindInvalidatesDestinationCredentials(Fixtures $fixtures, SmsLoginFixture $qa): void
{
    $db = $fixtures->database('qa_rebindcredentials');
    migrate($fixtures);
    rebindAccount($db, PHONE);
    rebindAccount($db, SMS_MEMBER);
    $qa->clock(SMS_TIME);
    $qa->withHost('rebind-destination-credentials', static function (int $port) use ($qa): void {
        $first = smsToken(smsLogin($port, $qa->issue($port), purpose: 'organization'));
        $second = smsToken(smsLogin($port, $qa->issue($port, SMS_MEMBER), phone: SMS_MEMBER, purpose: 'organization'));
        $staleFlow = rebindProof($qa, $port, $first, PHONE, REBIND_NEW);
        $qa->clock(SMS_TIME + 60);
        $staleCode = rebindIssue($qa, $port, $first, REBIND_NEW, "$staleFlow/new/send");
        $qa->clock(SMS_TIME + 120);
        $claim = rebindProof($qa, $port, $second, SMS_MEMBER, REBIND_NEW);
        phoneChangeRequest($port, $second, "$claim/complete");
        phoneChangeRequest($port, $first, "$staleFlow/complete", status: 409, code: 'phone_already_used');
        $qa->clock(SMS_TIME + 180);
        $second = smsToken(smsLogin($port, $qa->issue($port, REBIND_NEW), phone: REBIND_NEW, purpose: 'organization'));
        $release = rebindProof($qa, $port, $second, REBIND_NEW, REBIND_OTHER);
        phoneChangeRequest($port, $second, "$release/complete");
        phoneChangeRequest($port, $first, "$staleFlow/new/verify", $staleCode, 404, 'not_found');
        phoneChangeRequest($port, $first, "$staleFlow/complete", status: 404, code: 'not_found');
    });
    echo "PASS releasing a phone invalidates earlier rebind codes and proofs addressed to it\n";
}
