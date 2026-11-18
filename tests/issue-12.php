<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

const EMAIL_CHANGE = '/saas/account/email/change';
const EMAIL_CHANGE_NEW = 'updated@example.test';
const EMAIL_CHANGE_OCCUPIED = 'occupied@example.test';

function emailChangeRequest(
    int $port,
    ?string $access,
    string $step = '',
    array $data = [],
    int $status = 200,
    string $code = 'success',
): array {
    return smsExpect(smsRequest($port, 'POST', EMAIL_CHANGE . $step, $data,
        $access === null ? [] : sessionBearer($access)), $status, $code, 'Email change ' . $step)['data'] ?? [];
}

/** @return array{0: string, 1: array{challenge_id: string, code: string}} */
function emailChangeIssue(
    SmsLoginFixture $auth,
    int $port,
    string $access,
    string $recipient,
    string $step = '',
    array $data = [],
    string $channel = 'email',
): array {
    $smsBefore = count($auth->messages());
    $emailBefore = count(emailMessages($auth));
    $result = emailChangeRequest($port, $access, $step, $data);
    $id = $result['challenge_id'] ?? '';
    $expected = ['accepted' => true, 'challenge_id' => $id];
    $flow = '';
    if ($step === '') {
        check(preg_match('/\A[0-9a-f]{32}\z/', $result['flow_id'] ?? '') === 1, 'Expected an opaque email change flow');
        $flow = '/' . $result['flow_id'];
        $expected['flow_id'] = $result['flow_id'];
    }
    check(preg_match('/\A[0-9a-f]{32}\z/', $id) === 1 && $result == $expected && $result['accepted'] === true,
        'Email change send must return only acceptance and opaque IDs');

    $texts = $auth->messages();
    $emails = emailMessages($auth);
    $sms = $channel === 'sms';
    check(count($texts) === $smsBefore + (int) $sms && count($emails) === $emailBefore + (int) !$sms,
        'Email change must send exactly one message on the requested channel');
    $message = $sms ? $texts[$smsBefore] : $emails[$emailBefore];
    check(($sms ? $message['phone'] : $message['email']) === $recipient
        && $message['purpose'] === ($sms ? 'account_security' : 'change')
        && preg_match('/\A[0-9]{6}\z/', $message['code']) === 1, 'Email change used the wrong recipient, purpose, or OTP format');

    return [$flow, ['challenge_id' => $id, 'code' => $message['code']]];
}

function prepareEmailChange(SmsLoginFixture $auth, int $port, string $access, string $old, string $new): string
{
    [$flow, $source] = emailChangeIssue($auth, $port, $access, $old, data: ['new_email' => $new, 'channel' => 'email']);
    emailChangeRequest($port, $access, "$flow/old/verify", $source);
    [, $destination] = emailChangeIssue($auth, $port, $access, strtolower($new), "$flow/new/send");
    emailChangeRequest($port, $access, "$flow/new/verify", $destination);

    return $flow;
}

function testEmailChange(Fixtures $fixtures, SmsLoginFixture $auth): void
{
    testEmailChangeDeadlines($fixtures, $auth);

    $db = $fixtures->database('qa_emailchange');
    migrate($fixtures);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $occupied = rebindAccount($db, SMS_MEMBER);
    $db->prepare('UPDATE starter_accounts SET email = ?, email_verified_at = NOW() WHERE id = ?')
        ->execute([EMAIL_CHANGE_OCCUPIED, $occupied]);
    $owner = (int) $db->query("SELECT account_id FROM starter_platform_admins WHERE status = 'active'")->fetchColumn();
    rebindInvitations($db, $owner, PHONE);
    $memberships = $db->query('SELECT * FROM starter_memberships ORDER BY organization_id, account_id')->fetchAll(PDO::FETCH_ASSOC);
    $admins = $db->query('SELECT * FROM starter_platform_admins ORDER BY account_id')->fetchAll(PDO::FETCH_ASSOC);
    $auth->outcome('accepted');
    $auth->clock(SMS_TIME);
    $auth->withHost('email-change-credentials', static function (int $port) use ($auth, $db, $owner, $occupied, $memberships, $admins): void {
        $current = sessionTokens(smsLogin($port, $auth->issue($port)));
        $access = $current['access_token'];
        emailBind($port, $access, EMAIL_ADMIN, emailChallenge($auth, $port, $access, EMAIL_ADMIN));
        emailPassword($port, $access, RESET_OLD_PASSWORD);
        $platform = sessionTokens(emailLogin($port, EMAIL_ADMIN, RESET_OLD_PASSWORD)['data']);
        $organization = sessionTokens(emailLogin($port, EMAIL_ADMIN, RESET_OLD_PASSWORD, purpose: 'organization')['data']);
        sessionPlatform($port, $platform['access_token']);
        smsExpect(smsRequest($port, 'GET', ACCOUNT_PROFILE, headers: sessionBearer($organization['access_token'])),
            200, 'success', 'Organization session before email change');

        $args = ['new_email' => EMAIL_CHANGE_NEW, 'channel' => 'email'];
        emailChangeRequest($port, null, data: $args, status: 401, code: 'unauthenticated');
        $before = count(emailMessages($auth));
        emailChangeRequest($port, $access, data: ['new_email' => strtoupper(EMAIL_CHANGE_OCCUPIED)] + $args,
            status: 409, code: 'email_already_used');
        emailChangeRequest($port, $access, data: ['new_email' => null] + $args, status: 422, code: 'email_invalid');
        smsExpect(smsRequest($port, 'DELETE', '/saas/account/email', headers: sessionBearer($access)),
            404, 'route_not_found', 'Direct unlink of verified email');
        check(count(emailMessages($auth)) === $before, 'A conflict or unlink request sent verification mail');

        $reset = resetChallenge($auth, $port, EMAIL_ADMIN);
        $recovery = recoveryChallenge($auth, $port, EMAIL_ADMIN) + ['new_phone' => REBIND_OTHER];
        $proof = recoveryRequest($port, '/verify', $recovery)['data'];
        $recoveryFlow = '/' . $proof['flow_id'];
        $recoveryToken = ['recovery_token' => $proof['recovery_token']];
        $newPhone = recoverySms($auth, $port, $proof['flow_id'], $recoveryToken, REBIND_OTHER);
        recoveryRequest($port, "$recoveryFlow/new/verify", $recoveryToken + $newPhone);

        $auth->clock(SMS_TIME + 60);
        $pendingRecovery = recoveryChallenge($auth, $port, EMAIL_ADMIN) + ['new_phone' => REBIND_OTHER];
        $flow = prepareEmailChange($auth, $port, $access, EMAIL_ADMIN, strtoupper(EMAIL_CHANGE_NEW));

        // Model a competing binding after the destination was verified.
        $setEmail = $db->prepare('UPDATE starter_accounts SET email = ? WHERE id = ?');
        $setEmail->execute([EMAIL_CHANGE_NEW, $occupied]);
        emailChangeRequest($port, $access, "$flow/complete", status: 409, code: 'email_already_used');
        $setEmail->execute([EMAIL_CHANGE_OCCUPIED, $occupied]);

        $db->exec("CREATE TRIGGER qa_emailchange_reject_audit BEFORE INSERT ON starter_audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa01 forced email change audit failure'");
        $beforeFailure = snapshot($db);
        try {
            emailChangeRequest($port, $access, "$flow/complete", status: 503, code: 'audit_unavailable');
            check(snapshot($db) === $beforeFailure, 'Failed audit partially changed email, flow, or session state');
            sessionPlatform($port, $access);
            sessionPlatform($port, $platform['access_token']);
        } finally {
            $db->exec('DROP TRIGGER qa_emailchange_reject_audit');
        }

        check(emailChangeRequest($port, $access, "$flow/complete") === ['changed' => true], 'Email change must complete without issuing tokens');
        sessionPlatform($port, $access);
        $retained = sessionTokens(sessionRefresh($port, $current['refresh_token']));
        sessionPlatform($port, $retained['access_token']);
        sessionPlatform($port, $platform['access_token'], 401, 'token_invalid');
        smsExpect(smsRequest($port, 'GET', ACCOUNT_PROFILE, headers: sessionBearer($organization['access_token'])),
            401, 'token_invalid', 'Organization access revoked immediately by email change');
        sessionRefresh($port, $platform['refresh_token'], 401, 'token_invalid');
        sessionRefresh($port, $organization['refresh_token'], 401, 'token_invalid');
        emailChangeRequest($port, $access, "$flow/complete", status: 404, code: 'not_found');

        resetComplete($port, EMAIL_ADMIN, $reset, 401, 'purpose_mismatch');
        recoveryRequest($port, '/verify', $pendingRecovery, 401, 'purpose_mismatch');
        recoveryRequest($port, "$recoveryFlow/complete", $recoveryToken, 401, 'credential_expired');
        emailLogin($port, EMAIL_ADMIN, RESET_OLD_PASSWORD, 401, 'credentials_invalid');
        emailLogin($port, EMAIL_CHANGE_NEW, RESET_NEW_PASSWORD, 401, 'credentials_invalid');
        $moved = sessionTokens(emailLogin($port, EMAIL_CHANGE_NEW, RESET_OLD_PASSWORD)['data']);
        checkEmailChangeIdentity($port, $moved['access_token'], $owner);
        checkEmailChangeIdentity($port, smsToken(smsLogin($port, $auth->issue($port))), $owner);

        $auth->clock(SMS_TIME + 120);
        $back = prepareEmailChange($auth, $port, $retained['access_token'], EMAIL_CHANGE_NEW, EMAIL_ADMIN);
        emailChangeRequest($port, $retained['access_token'], "$back/complete");
        resetComplete($port, EMAIL_ADMIN, $reset, 401, 'purpose_mismatch');
        recoveryRequest($port, '/verify', $pendingRecovery, 401, 'purpose_mismatch');
        recoveryRequest($port, "$recoveryFlow/complete", $recoveryToken, 401, 'credential_expired');
        emailLogin($port, EMAIL_ADMIN, RESET_NEW_PASSWORD, 401, 'credentials_invalid');
        $returned = sessionTokens(emailLogin($port, EMAIL_ADMIN, RESET_OLD_PASSWORD)['data']);
        checkEmailChangeIdentity($port, $returned['access_token'], $owner);
        $phone = smsToken(smsLogin($port, $auth->issue($port)));
        checkEmailChangeIdentity($port, $phone, $owner);
        check((int) $db->query('SELECT COUNT(*) FROM starter_accounts')->fetchColumn() === 2,
            'Email change or old proof submissions created or merged accounts');
        checkEmailChangeAudit($db, $owner, ['email', 'email']);
        check($db->query('SELECT * FROM starter_memberships ORDER BY organization_id, account_id')->fetchAll(PDO::FETCH_ASSOC) === $memberships
            && $db->query('SELECT * FROM starter_platform_admins ORDER BY account_id')->fetchAll(PDO::FETCH_ASSOC) === $admins,
            'Changing email changed a membership or platform qualification');

        // An ordinary password reset must revoke the very session retained by email change.
        $freshReset = resetChallenge($auth, $port, EMAIL_ADMIN);
        resetComplete($port, EMAIL_ADMIN, $freshReset, 200, 'success');
        sessionPlatform($port, $retained['access_token'], 401, 'token_invalid');
        sessionRefresh($port, $retained['refresh_token'], 401, 'token_invalid');
        checkEmailChangeRetentionCleared($db, $owner);

        // Establish fresh retention through another email change, then revoke it through phone rebind.
        $auth->clock(SMS_TIME + 180);
        $afterReset = sessionTokens(emailLogin($port, EMAIL_ADMIN, RESET_NEW_PASSWORD)['data']);
        $afterResetAccess = $afterReset['access_token'];
        $again = prepareEmailChange($auth, $port, $afterResetAccess, EMAIL_ADMIN, EMAIL_CHANGE_NEW);
        emailChangeRequest($port, $afterResetAccess, "$again/complete");
        sessionPlatform($port, $afterResetAccess);
        $rebind = rebindProof($auth, $port, $afterResetAccess, PHONE, REBIND_NEW);
        phoneChangeRequest($port, $afterResetAccess, "$rebind/complete");
        sessionPlatform($port, $afterResetAccess, 401, 'token_invalid');
        sessionRefresh($port, $afterReset['refresh_token'], 401, 'token_invalid');
        checkEmailChangeRetentionCleared($db, $owner);
    });
    echo "PASS email change conflict and audit rollback, identity invariants, session retention and revocation, stale email credentials after switch and switchback\n";
}

function checkEmailChangeIdentity(int $port, string $access, int $account): void
{
    $me = smsExpect(smsRequest($port, 'GET', PLATFORM_ME, headers: sessionBearer($access)), 200, 'success', 'Email change identity');
    check($me['data'] === ['account_id' => $account], 'Email change changed the account ID or removed platform qualification');
}

function checkEmailChangeRetentionCleared(PDO $db, int $account): void
{
    $state = $db->query("SELECT retained_session_id, retained_session_epoch, retained_at_epoch
        FROM starter_accounts WHERE id = $account")->fetch(PDO::FETCH_NUM);
    check($state === [null, null, null], 'An account-wide revocation left the retained email change session recorded');
}

/** @param list<string> $sourceChannels */
function checkEmailChangeAudit(PDO $db, int $account, array $sourceChannels): void
{
    $audits = $db->query("SELECT actor_account_id, target_account_id, metadata FROM starter_audit_logs
        WHERE action = 'email_changed' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check(count($audits) === count($sourceChannels), 'Expected exactly one audit per committed email change');
    foreach ($audits as $index => $audit) {
        $metadata = json_decode($audit['metadata'], true, flags: JSON_THROW_ON_ERROR);
        ksort($metadata);
        check((int) $audit['actor_account_id'] === $account && (int) $audit['target_account_id'] === $account
            && $metadata === ['result' => 'success', 'source_channel' => $sourceChannels[$index]],
            'Email change audit must identify the actors and contain only the result and source channel');
    }
}

function testEmailChangeDeadlines(Fixtures $fixtures, SmsLoginFixture $auth): void
{
    $db = $fixtures->database('qa_emailchangedeadlines');
    migrate($fixtures);
    $account = rebindAccount($db, PHONE);
    $auth->clock(SMS_TIME);
    $auth->outcome('accepted');
    $auth->withHost('email-change-deadlines', static function (int $port) use ($auth, $db): void {
        $session = sessionTokens(smsLogin($port, $auth->issue($port), purpose: 'organization'));
        $access = $session['access_token'];
        $args = ['new_email' => EMAIL_CHANGE_NEW, 'channel' => 'sms'];
        emailChangeRequest($port, $access, data: $args, status: 403, code: 'forbidden');
        emailBind($port, $access, EMAIL_ADMIN, emailChallenge($auth, $port, $access, EMAIL_ADMIN));
        [$flow, $source] = emailChangeIssue($auth, $port, $access, '+86' . PHONE, data: $args, channel: 'sms');
        $before = count(emailMessages($auth));
        emailChangeRequest($port, $access, "$flow/new/send", status: 401, code: 'credential_expired');
        emailChangeRequest($port, $access, "$flow/new/verify", $source, 401, 'credential_expired');
        emailChangeRequest($port, $access, "$flow/complete", status: 401, code: 'credential_expired');
        check(count(emailMessages($auth)) === $before, 'Unverified source triggered a destination email');

        $auth->clock(SMS_TIME + 300);
        emailChangeRequest($port, $access, "$flow/old/verify", $source, 401, 'credential_expired');
        [, $source] = emailChangeIssue($auth, $port, $access, '+86' . PHONE, "$flow/old/send", channel: 'sms');
        $wrong = array_replace($source, ['code' => wrongSmsCode($source['code'])]);
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            emailChangeRequest($port, $access, "$flow/old/verify", $wrong, 401, 'verification_code_invalid');
        }
        emailChangeRequest($port, $access, "$flow/old/verify", $wrong, 401, 'verification_attempts_exceeded');
        emailChangeRequest($port, $access, "$flow/old/verify", $source, 401, 'credential_expired');

        $auth->clock(SMS_TIME + 360);
        [, $source] = emailChangeIssue($auth, $port, $access, '+86' . PHONE, "$flow/old/send", channel: 'sms');
        [$phoneFlow] = rebindStart($auth, $port, $access, PHONE, REBIND_NEW);
        phoneChangeRequest($port, $access, "$phoneFlow/old/verify", $source, 401, 'purpose_mismatch');
        $wrong = array_replace($source, ['code' => wrongSmsCode($source['code'])]);
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            emailChangeRequest($port, $access, "$flow/old/verify", $wrong, 401, 'verification_code_invalid');
        }
        $proof = emailChangeRequest($port, $access, "$flow/old/verify", $source);
        check($proof === ['verified_until' => gmdate(DATE_ATOM, SMS_TIME + 960)], 'Source proof did not get its own 600-second deadline');
        emailChangeRequest($port, $access, "$flow/old/verify", $source, 401, 'credential_expired');
        phoneChangeRequest($port, $access, "$phoneFlow/new/send", $proof, 401, 'credential_expired');
        phoneChangeRequest($port, $access, "$phoneFlow/complete", $proof, 401, 'credential_expired');
        phoneChangeRequest($port, $access, "$flow/complete", $proof, 404, 'not_found');
        emailChangeRequest($port, $access, "$flow/complete", status: 401, code: 'credential_expired');

        $auth->clock(SMS_TIME + 420);
        [, $destination] = emailChangeIssue($auth, $port, $access, EMAIL_CHANGE_NEW, "$flow/new/send");
        check(emailChangeRequest($port, $access, "$flow/new/verify", $destination) === ['verified_until' => gmdate(DATE_ATOM, SMS_TIME + 1020)],
            'Destination proof did not get its own 600-second deadline');
        $auth->clock(SMS_TIME + 661);
        [, $source] = emailChangeIssue($auth, $port, $access, '+86' . PHONE, "$flow/old/send", channel: 'sms');
        $auth->clock(SMS_TIME + 899);
        $access = smsToken(sessionRefresh($port, $session['refresh_token']));
        $auth->clock(SMS_TIME + 960);
        emailChangeRequest($port, $access, "$flow/new/send", status: 401, code: 'credential_expired');
        emailChangeRequest($port, $access, "$flow/complete", status: 401, code: 'credential_expired');
        check(emailChangeRequest($port, $access, "$flow/old/verify", $source) === ['verified_until' => gmdate(DATE_ATOM, SMS_TIME + 1560)],
            'A 299-second-old SMS must renew the source proof for 600 seconds');
        $destinationDeadline = $db->prepare('SELECT new_verified_until FROM starter_email_change_flows WHERE id = ?');
        $destinationDeadline->execute([substr($flow, 1)]);
        check($destinationDeadline->fetchColumn() === gmdate('Y-m-d H:i:s', SMS_TIME + 1020),
            'Renewing the source proof cleared or extended the destination deadline');
        $auth->clock(SMS_TIME + 1020);
        emailChangeRequest($port, $access, "$flow/complete", status: 401, code: 'credential_expired');
        [, $destination] = emailChangeIssue($auth, $port, $access, EMAIL_CHANGE_NEW, "$flow/new/send");
        emailChangeRequest($port, $access, "$flow/new/verify", $destination);
        $auth->clock(SMS_TIME + 1559);
        check(emailChangeRequest($port, $access, "$flow/complete") === ['changed' => true],
            'Email change must succeed with both proofs still live after the source SMS expires');
    });
    checkEmailChangeAudit($db, $account, ['sms']);
    echo "PASS email change independent proof deadlines, account_security SMS expiry and attempts, single use, and rebind isolation\n";
}
