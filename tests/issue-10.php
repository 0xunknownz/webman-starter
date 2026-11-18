<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

const EMAIL_RECOVERY = '/saas/auth/phone/recovery/email';
const RECOVERY_EMAIL = 'recovery@example.test';
const RECOVERY_UNVERIFIED = 'unverified-recovery@example.test';

function recoveryRequest(
    int $port,
    string $step,
    array $data = [],
    int $status = 200,
    string $code = 'success',
    array $headers = [],
): array {
    return smsExpect(smsRequest($port, 'POST', EMAIL_RECOVERY . $step, $data, $headers), $status, $code, 'Email recovery ' . $step);
}

function recoveryChallenge(SmsLoginFixture $auth, int $port, string $email = RECOVERY_EMAIL): array
{
    $before = count(emailMessages($auth));
    $data = recoveryRequest($port, '/send', ['email' => $email])['data'];
    $messages = emailMessages($auth);
    check(count($messages) === $before + 1 && $messages[$before]['purpose'] === 'recovery'
        && $messages[$before]['email'] === strtolower($email), 'Expected one captured recovery email');

    return ['email' => $email, 'challenge_id' => $data['challenge_id'], 'code' => $messages[$before]['code']];
}

function recoverySms(SmsLoginFixture $auth, int $port, string $flow, array $token, string $phone): array
{
    $before = count($auth->messages());
    $data = recoveryRequest($port, "/$flow/new/send", $token)['data'];
    $messages = $auth->messages();
    check(count($messages) === $before + 1 && $messages[$before]['purpose'] === 'rebind'
        && $messages[$before]['phone'] === '+86' . $phone, 'Recovery must send SMS only to the verified flow destination');

    return ['challenge_id' => $data['challenge_id'], 'code' => $messages[$before]['code']];
}

function testEmailRecovery(Fixtures $fixtures, SmsLoginFixture $auth): void
{
    $db = $fixtures->database('qa_emailrecovery');
    migrate($fixtures);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $account = (int) $db->query("SELECT account_id FROM starter_platform_admins WHERE status = 'active'")->fetchColumn();
    $unverified = rebindAccount($db, SMS_MEMBER);
    $db->prepare('UPDATE starter_accounts SET email = ? WHERE id = ?')->execute([RECOVERY_UNVERIFIED, $unverified]);
    [$invites] = rebindInvitations($db, $account, PHONE);
    $auth->outcome('accepted');
    $auth->clock(SMS_TIME);
    $auth->withHost('email-recovery-complete', static function (int $port) use ($auth, $db, $account, $invites): void {
        $platform = sessionTokens(smsLogin($port, $auth->issue($port)));
        $auth->clock(SMS_TIME + 60);
        $org = sessionTokens(smsLogin($port, $auth->issue($port), purpose: 'organization'));
        $auth->clock(SMS_TIME + 120);
        emailBind($port, $platform['access_token'], RECOVERY_EMAIL,
            emailChallenge($auth, $port, $platform['access_token'], RECOVERY_EMAIL));
        $auth->clock(SMS_TIME + 180);
        $before = count(emailMessages($auth));
        resetSend($port, RECOVERY_EMAIL);
        check(count(emailMessages($auth)) === $before, 'A passwordless account received a password reset email');

        $email = recoveryChallenge($auth, $port, strtoupper(RECOVERY_EMAIL));
        $before = count($auth->messages());
        $redeem = $email + ['new_phone' => REBIND_NEW];
        $proof = recoveryRequest($port, '/verify', $redeem)['data'];
        check(count($auth->messages()) === $before && array_keys($proof) === ['flow_id', 'recovery_token', 'verified_until']
            && preg_match('/\A[0-9a-f]{32}\z/', $proof['flow_id']) === 1
            && preg_match('/\A[0-9a-f]{64}\z/', $proof['recovery_token']) === 1
            && $proof['verified_until'] === gmdate(DATE_ATOM, SMS_TIME + 780), 'Email proof must establish one scoped 600-second credential');
        recoveryRequest($port, '/verify', $redeem, 401, 'credential_expired');
        $flow = $proof['flow_id'];
        $token = ['recovery_token' => $proof['recovery_token']];
        recoveryRequest($port, "/$flow/complete", $token, 401, 'credential_expired');
        recoveryRequest($port, "/$flow/new/send", [], 401, 'credential_expired', sessionBearer($platform['access_token']));
        recoveryRequest($port, "/$flow/new/send", ['recovery_token' => str_repeat('0', 64)], 401, 'credential_expired');
        phoneChangeRequest($port, $platform['access_token'], "/$flow/new/send", $token, 404, 'not_found');
        sessionPlatform($port, $proof['recovery_token'], 401, 'token_invalid');

        $auth->clock(SMS_TIME + 500); // Email OTP has expired. Its independently earned proof is still live.
        $sms = recoverySms($auth, $port, $flow, $token + ['new_phone' => REBIND_OTHER], REBIND_NEW);
        check(recoveryRequest($port, "/$flow/new/verify", $token + $sms)['data'] === ['verified_until' => gmdate(DATE_ATOM, SMS_TIME + 1100)],
            'New phone proof must have its own deadline');
        $auth->clock(SMS_TIME + 779);
        $oldLogin = $auth->issue($port);
        $occupied = rebindAccount($db, REBIND_NEW);
        $snapshot = snapshot($db);
        recoveryRequest($port, "/$flow/complete", $token, 409, 'phone_already_used');
        check(snapshot($db) === $snapshot, 'Destination conflict left a partial recovery change');
        $db->exec("DELETE FROM starter_accounts WHERE id = $occupied");
        $db->exec("CREATE TRIGGER qa_recovery_reject_audit BEFORE INSERT ON starter_audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa01 forced recovery audit failure'");
        $snapshot = snapshot($db);
        try {
            recoveryRequest($port, "/$flow/complete", $token, 503, 'audit_unavailable');
            check(snapshot($db) === $snapshot, 'Audit failure left a partial recovery change');
            sessionPlatform($port, $platform['access_token']);
        } finally {
            $db->exec('DROP TRIGGER qa_recovery_reject_audit');
        }
        check(recoveryRequest($port, "/$flow/complete", $token)['data'] === ['changed' => true],
            'Successful recovery should complete without issuing a login session');
        recoveryRequest($port, "/$flow/complete", $token, 404, 'not_found');
        sessionPlatform($port, $platform['access_token'], 401, 'token_invalid');
        sessionRefresh($port, $platform['refresh_token'], 401, 'token_invalid');
        sessionRefresh($port, $org['refresh_token'], 401, 'token_invalid');
        smsLogin($port, $oldLogin, status: 401, code: 'purpose_mismatch');
        $newAccess = smsToken(smsLogin($port, $auth->issue($port, REBIND_NEW), phone: REBIND_NEW));
        $me = smsExpect(smsRequest($port, 'GET', PLATFORM_ME, headers: sessionBearer($newAccess)), 200, 'success', 'Recovered account');
        check($me['data'] === ['account_id' => $account], 'Recovery must preserve the existing platform identity');

        $ids = implode(',', $invites);
        $rows = $db->query("SELECT status, reserved FROM starter_invitations WHERE id IN ($ids) ORDER BY id")->fetchAll(PDO::FETCH_NUM);
        check(array_column($rows, 0) === ['revoked', 'revoked', 'revoked', 'accepted', 'revoked']
            && array_sum(array_column($rows, 1)) === 0, 'Recovery must revoke unconsumed invitations and release reservations');
        check((int) $db->query("SELECT COUNT(*) FROM starter_memberships WHERE account_id = $account AND role = 'owner'")->fetchColumn() === 1,
            'Recovery must preserve existing memberships');
        $audit = $db->query("SELECT actor_account_id, target_account_id, metadata FROM starter_audit_logs WHERE action = 'phone_changed'")->fetch(PDO::FETCH_ASSOC);
        $metadata = json_decode($audit['metadata'], true, flags: JSON_THROW_ON_ERROR);
        ksort($metadata);
        check((int) $audit['actor_account_id'] === $account && (int) $audit['target_account_id'] === $account
            && $metadata === ['method' => 'email_recovery', 'new_phone_masked' => '**********9001',
                'old_phone_masked' => '**********8000', 'result' => 'success'], 'Recovery success audit must identify actor and target and redact all credentials');
    }, ['STARTER_EMAIL_TTL' => '300']);
    echo "PASS email recovery without password or old SMS, scoped proof, conflict and audit rollback, cleanup and sessions\n";

    testEmailRecoveryDelivery($db, $auth, $account);
    testEmailRecoveryBoundProof($fixtures, $db, $auth, $account);
}

function testEmailRecoveryDelivery(PDO $db, SmsLoginFixture $auth, int $account): void
{
    $previousAudit = (int) $db->query('SELECT MAX(id) FROM starter_audit_logs')->fetchColumn();
    $auth->withHost('email-recovery-delivery', static function (int $port) use ($auth): void {
        $auth->clock(SMS_TIME);
        $before = count(emailMessages($auth));
        recoveryRequest($port, '/send', ['email' => 'invalid-email'], 422, 'email_invalid');
        $skipped = [];
        foreach (['unbound-recovery@example.test', RECOVERY_UNVERIFIED] as $email) {
            $body = recoveryRequest($port, '/send', ['email' => $email]);
            $skipped[] = $body;
            recoveryRequest($port, '/verify', [
                'email' => $email, 'challenge_id' => $body['data']['challenge_id'], 'code' => '000000', 'new_phone' => REBIND_OTHER,
            ], 401, 'verification_code_invalid');
        }
        check(count(emailMessages($auth)) === $before, 'An ineligible mailbox reached the recovery sender');
        $outcome = $auth->stateDirectory() . '/email-outcome';
        $sent = [];
        try {
            foreach (['accepted', 'failed', 'timeout'] as $i => $status) {
                $auth->clock(SMS_TIME + 60 * $i);
                check(file_put_contents($outcome, $status) !== false, 'Could not set recovery email supplier outcome');
                $sent[] = recoveryRequest($port, '/send', ['email' => strtoupper(RECOVERY_EMAIL)]);
                recoveryRequest($port, '/send', ['email' => RECOVERY_EMAIL], 429, 'email_rate_limited');
            }
            recoveryRequest($port, '/send', ['email' => 'over-quota-recovery@example.test'], 429, 'email_rate_limited');
        } finally {
            check(file_put_contents($outcome, 'accepted') !== false, 'Could not restore recovery email supplier acceptance');
        }
        $ids = [];
        foreach ([...$skipped, ...$sent] as $body) {
            $ids[] = $id = $body['data']['challenge_id'] ?? '';
            check(preg_match('/\A[0-9a-f]{32}\z/', $id) === 1 && $body['data'] === ['accepted' => true, 'challenge_id' => $id]
                && smsPublicShape($body) === smsPublicShape($sent[0]), 'Public recovery send disclosed eligibility or supplier outcome');
        }
        check(count(array_unique($ids)) === 5, 'Recovery reused a public receipt');
        $submissions = array_slice(emailMessages($auth), $before);
        check(count($submissions) === 3, 'Recovery sender must receive only eligible requests within quota');
        foreach ($submissions as $message) {
            check($message['email'] === RECOVERY_EMAIL && $message['purpose'] === 'recovery'
                && preg_match('/\A[0-9]{6}\z/', $message['code']) === 1, 'Wrong recovery mail destination, purpose or format');
        }
    }, ['STARTER_EMAIL_PER_HOUR_PER_IP' => '5']);

    $audits = $db->query("SELECT action, actor_account_id, target_account_id, metadata FROM starter_audit_logs WHERE id > $previousAudit
        AND action IN ('email_submission_accepted', 'email_submission_failed', 'email_submission_timeout') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check(array_column($audits, 'action') === ['email_submission_accepted', 'email_submission_failed', 'email_submission_timeout'],
        'Recovery provider outcomes must be independently auditable');
    foreach ($audits as $audit) {
        check($audit['actor_account_id'] === null && (int) $audit['target_account_id'] === $account
            && json_decode($audit['metadata'], true, flags: JSON_THROW_ON_ERROR) === ['purpose' => 'recovery'],
            'Recovery submission audit leaked recipient or credential details');
    }
    echo "PASS email recovery uniform public receipts, provider outcomes and nonrefundable quotas\n";
}

function testEmailRecoveryBoundProof(Fixtures $fixtures, PDO $db, SmsLoginFixture $auth, int $account): void
{
    $auth->withHost('email-recovery-bound-proof', static function (int $port) use ($fixtures, $db, $auth, $account): void {
        $auth->clock(SMS_TIME);
        $challenge = recoveryChallenge($auth, $port);
        recoveryRequest($port, '/verify', $challenge + ['new_phone' => '+12025550123'], 422, 'phone_invalid');
        $first = recoveryRequest($port, '/verify', $challenge + ['new_phone' => PHONE])['data'];
        $auth->clock(SMS_TIME + 60);
        $second = recoveryRequest($port, '/verify', recoveryChallenge($auth, $port) + ['new_phone' => REBIND_OTHER])['data'];
        $flow = $first['flow_id'];
        $other = $second['flow_id'];
        $token = ['recovery_token' => $first['recovery_token']];
        $otherToken = ['recovery_token' => $second['recovery_token']];
        recoveryRequest($port, "/$other/new/send", $token, 401, 'credential_expired');
        recoveryRequest($port, "/$flow/new/send", $otherToken, 401, 'credential_expired');
        $auth->clock(SMS_TIME + 500);
        $unused = recoveryChallenge($auth, $port) + ['new_phone' => PHONE];
        $sms = recoverySms($auth, $port, $flow, $token, PHONE);
        $otherSms = recoverySms($auth, $port, $other, $otherToken, REBIND_OTHER);
        recoveryRequest($port, "/$flow/new/verify", $token + $otherSms, 401, 'purpose_mismatch');
        $wrong = array_replace($sms, ['code' => wrongSmsCode($sms['code'])]);
        for ($i = 0; $i < 4; ++$i) {
            recoveryRequest($port, "/$flow/new/verify", $token + $wrong, 401, 'verification_code_invalid');
        }
        recoveryRequest($port, "/$flow/new/verify", $token + $wrong, 401, 'verification_attempts_exceeded');
        recoveryRequest($port, "/$flow/new/verify", $token + $sms, 401, 'credential_expired');
        $auth->clock(SMS_TIME + 560);
        $sms = recoverySms($auth, $port, $flow, $token, PHONE);
        recoveryRequest($port, "/$flow/new/verify", $token + $sms);
        recoveryRequest($port, "/$other/new/verify", $otherToken + $otherSms);
        $auth->clock(SMS_TIME + 600);
        recoveryRequest($port, "/$flow/complete", $token, 401, 'credential_expired');
        recoveryRequest($port, "/$flow/new/send", $token, 401, 'credential_expired');

        $client = null;
        $db->beginTransaction();
        try {
            $rotate = $db->prepare('UPDATE starter_accounts SET email = ?, email_epoch = email_epoch + 1 WHERE id = ?');
            $rotate->execute(['changed-recovery@example.test', $account]);
            $rotate->execute([RECOVERY_EMAIL, $account]);
            clearstatcache(true, $fixtures->sqlLog);
            $offset = filesize($fixtures->sqlLog);
            check($offset !== false, 'Could not locate the email recovery race in MySQL query log');
            $client = smsConnect($port, 'POST', EMAIL_RECOVERY . "/$other/complete", $otherToken);
            $deadline = microtime(true) + 2;
            do {
                $log = file_get_contents($fixtures->sqlLog, false, null, $offset);
                check($log !== false, 'Could not read the email recovery race in MySQL query log');
                if (preg_match('/from \x60starter_accounts\x60[^\r\n]*for update/i', $log) === 1) {
                    break;
                }
                check(microtime(true) < $deadline, 'Recovery did not reach the held account lock');
                usleep(1_000);
            } while (true);
            $db->commit();
            smsExpect(smsReadResponse($client), 401, 'credential_expired', 'Recovery rechecks the email epoch under account lock');
            recoveryRequest($port, "/$other/new/send", $otherToken, 401, 'credential_expired');
            recoveryRequest($port, '/verify', $unused, 401, 'purpose_mismatch');
            check($db->query("SELECT phone FROM starter_accounts WHERE id = $account")->fetchColumn() === '+86' . REBIND_NEW,
                'Stale email recovery changed the phone');
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (is_resource($client)) {
                fclose($client);
            }
        }
    });
    echo "PASS email recovery flow isolation, OTP budget, exact proof deadline and locked email change-back check\n";
}
