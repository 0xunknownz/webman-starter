<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

const RESET_SEND = '/saas/auth/password/reset/send';
const RESET_COMPLETE = '/saas/auth/password/reset/complete';
const RESET_OLD_PASSWORD = 'old-reset-password';
const RESET_NEW_PASSWORD = 'new-reset-password';

function testPasswordReset(Fixtures $fixtures, SmsLoginFixture $auth): void
{
    $db = $fixtures->database('qa_reset');
    migrate($fixtures);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $seed = $db->prepare('INSERT INTO starter_accounts (phone, created_at, updated_at) VALUES (?, NOW(), NOW())');
    $seed->execute(['+86' . SMS_MEMBER]);
    $seed->execute(['+86' . SMS_INVITED]);
    $seed->execute(['+86' . SMS_EXPIRED_INVITE]);
    $db->prepare('UPDATE starter_accounts SET email = ?, password_hash = ? WHERE phone = ?')
        ->execute(['unverified-reset@example.test', password_hash(RESET_OLD_PASSWORD, PASSWORD_BCRYPT), '+86' . SMS_EXPIRED_INVITE]);

    testResetDelivery($db, $auth);
    testResetProofsAndSessions($auth);
    testResetRechecksEmailUnderLock($fixtures, $db, $auth);
    testResetAuditRollback($db, $auth);
}

function testResetDelivery(PDO $db, SmsLoginFixture $auth): void
{
    $auth->withHost('reset-delivery', static function (int $port) use ($auth): void {
        $auth->clock(SMS_TIME);
        $platform = smsToken(smsLogin($port, $auth->issue($port)));
        emailBind($port, $platform, EMAIL_ADMIN, emailChallenge($auth, $port, $platform, EMAIL_ADMIN));
        emailPassword($port, $platform, RESET_OLD_PASSWORD);
        $member = smsToken(smsLogin($port, $auth->issue($port, SMS_MEMBER), phone: SMS_MEMBER, purpose: 'organization'));
        $withoutPassword = 'without-password@example.test';
        emailBind($port, $member, $withoutPassword, emailChallenge($auth, $port, $member, $withoutPassword));

        $before = count(emailMessages($auth));
        resetSend($port, 'not-an-email', 422, 'email_invalid');
        $unknown = resetSend($port, 'unknown@example.test');
        $disabled = resetSend($port, $withoutPassword);
        $unverified = resetSend($port, 'unverified-reset@example.test');
        check(count(emailMessages($auth)) === $before, 'Invalid or ineligible reset email reached the sender');

        $accepted = resetSend($port, strtoupper(EMAIL_ADMIN));
        $skipped = ['unknown@example.test' => $unknown, $withoutPassword => $disabled, 'unverified-reset@example.test' => $unverified];
        foreach ($skipped as $email => $body) {
            resetComplete($port, $email, ['challenge_id' => resetChallengeId($body), 'code' => '000000'],
                401, 'verification_code_invalid');
        }
        $outcomeFile = $auth->stateDirectory() . '/email-outcome';
        try {
            $auth->clock(SMS_TIME + 60);
            check(file_put_contents($outcomeFile, 'failed') !== false, 'Could not set reset mail rejection');
            $rejected = resetSend($port, EMAIL_ADMIN);
            $auth->clock(SMS_TIME + 119);
            resetSend($port, strtoupper(EMAIL_ADMIN), 429, 'email_rate_limited');
            $auth->clock(SMS_TIME + 120);
            check(file_put_contents($outcomeFile, 'timeout') !== false, 'Could not set reset mail timeout');
            $timedOut = resetSend($port, EMAIL_ADMIN);
            resetSend($port, 'another-unknown@example.test', 429, 'email_rate_limited');
        } finally {
            check(file_put_contents($outcomeFile, 'accepted') !== false, 'Could not restore reset mail acceptance');
        }

        $ids = [];
        foreach ([$unknown, $disabled, $unverified, $accepted, $rejected, $timedOut] as $body) {
            $ids[] = resetChallengeId($body);
            check($body['message'] === $accepted['message'] && array_keys($body) === array_keys($accepted),
                'Public password reset send disclosed eligibility or provider status');
        }
        check(count(array_unique($ids)) === count($ids), 'Reset challenge identifiers were reused');
        $submissions = array_slice(emailMessages($auth), $before);
        check(count($submissions) === 3, 'Reset sender should record exactly three eligible calls');
        foreach ($submissions as $submission) {
            check($submission['email'] === EMAIL_ADMIN && $submission['purpose'] === 'reset'
                && preg_match('/\A[0-9]{6}\z/', $submission['code']) === 1, 'Reset sender used the wrong recipient, purpose, or code');
        }
    }, ['STARTER_EMAIL_PER_HOUR_PER_IP' => '6']);

    $audits = $db->query("SELECT action, actor_account_id, target_account_id, metadata FROM starter_audit_logs
        WHERE action IN ('email_submission_accepted', 'email_submission_failed', 'email_submission_timeout') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check(array_column($audits, 'action') === ['email_submission_accepted', 'email_submission_failed', 'email_submission_timeout'],
        'Password reset provider acceptance, rejection and timeout must be independently auditable');
    $accountId = (int) $db->query("SELECT account_id FROM starter_platform_admins WHERE status = 'active'")->fetchColumn();
    foreach ($audits as $row) {
        check($row['actor_account_id'] === null && (int) $row['target_account_id'] === $accountId
            && json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR) === ['purpose' => 'reset'],
            'The reset delivery audit must identify the affected account and omit credentials and recipient');
    }
    echo "PASS reset eligibility, uniform responses, provider outcomes, and nonrefundable limits
";
}

/** @return array<string,mixed> */
function resetSend(int $port, string $email, int $status = 200, string $code = 'success'): array
{
    return smsExpect(smsRequest($port, 'POST', RESET_SEND, ['email' => $email]), $status, $code, 'Send reset email');
}

/** @param array<string,mixed> $body */
function resetChallengeId(array $body): string
{
    $id = $body['data']['challenge_id'] ?? null;
    check(is_string($id) && preg_match('/\A[0-9a-f]{32}\z/', $id) === 1
        && $body['data'] === ['accepted' => true, 'challenge_id' => $id], 'Reset send must expose only acceptance and an opaque ID');

    return $id;
}

/** @return array{challenge_id:string,code:string} */
function resetChallenge(SmsLoginFixture $auth, int $port, string $email): array
{
    $before = count(emailMessages($auth));
    $id = resetChallengeId(resetSend($port, $email));
    $messages = emailMessages($auth);
    check(count($messages) === $before + 1 && $messages[$before]['purpose'] === 'reset'
        && $messages[$before]['email'] === strtolower($email), 'Expected one captured reset code');

    return ['challenge_id' => $id, 'code' => $messages[$before]['code']];
}

/** @param array{challenge_id:string,code:string} $challenge */
function resetComplete(
    int $port,
    string $email,
    array $challenge,
    int $status,
    string $code,
    string $password = RESET_NEW_PASSWORD,
): void {
    $response = smsExpect(smsRequest($port, 'POST', RESET_COMPLETE, ['email' => $email, 'password' => $password] + $challenge),
        $status, $code, 'Complete password reset');
    check($response['data'] === null, 'Password reset completion must not return authentication tokens');
}

function testResetProofsAndSessions(SmsLoginFixture $auth): void
{
    $auth->withHost('reset-complete', static function (int $port) use ($auth): void {
        $auth->clock(SMS_TIME);
        $other = smsToken(smsLogin($port, $auth->issue($port, SMS_INVITED), phone: SMS_INVITED, purpose: 'organization'));
        $wrongPurpose = emailChallenge($auth, $port, $other, 'other-bind@example.test');
        resetComplete($port, EMAIL_ADMIN, $wrongPurpose, 401, 'purpose_mismatch');
        $expired = resetChallenge($auth, $port, EMAIL_ADMIN);
        $auth->clock(SMS_TIME + 600);
        resetComplete($port, EMAIL_ADMIN, $expired, 401, 'credential_expired');

        $oldHash = resetChallenge($auth, $port, EMAIL_ADMIN);
        $passwordSetter = smsToken(emailLogin($port, EMAIL_ADMIN, RESET_OLD_PASSWORD)['data']);
        emailPassword($port, $passwordSetter, RESET_OLD_PASSWORD);
        resetComplete($port, EMAIL_ADMIN, $oldHash, 401, 'purpose_mismatch');

        $auth->clock(SMS_TIME + 660);
        $platformSms = sessionTokens(smsLogin($port, $auth->issue($port)));
        $platformEmail = sessionTokens(emailLogin($port, EMAIL_ADMIN, RESET_OLD_PASSWORD)['data']);
        $orgEmail = sessionTokens(emailLogin($port, EMAIL_ADMIN, RESET_OLD_PASSWORD, purpose: 'organization')['data']);
        sessionPlatform($port, $platformSms['access_token']);
        sessionPlatform($port, $platformEmail['access_token']);
        smsExpect(smsRequest($port, 'GET', ACCOUNT_PROFILE, headers: emailBearer($orgEmail['access_token'])),
            200, 'success', 'Organization session works before password reset');

        $valid = resetChallenge($auth, $port, EMAIL_ADMIN);
        $wrongCode = ['code' => wrongSmsCode($valid['code'])] + $valid;
        resetComplete($port, EMAIL_ADMIN, $wrongCode, 401, 'verification_code_invalid');
        resetComplete($port, EMAIL_ADMIN, $valid, 422, 'validation_failed', 'short');
        $first = ['email' => strtoupper(EMAIL_ADMIN), 'password' => RESET_NEW_PASSWORD] + $valid;
        $second = ['email' => EMAIL_ADMIN, 'password' => 'competing-reset-password'] + $valid;
        $responses = $auth->race($port, SMS_TIME + 661, RESET_COMPLETE, [$first, $second]);
        smsRaceResults($responses, ['200:success', '401:credential_expired']);
        $winner = $responses[0][0] === 200 ? $first : $second;
        $loser = $responses[0][0] === 200 ? $second : $first;
        foreach ($responses as [, $body]) {
            check($body['data'] === null, 'Concurrent reset response must not return tokens');
        }
        resetComplete($port, EMAIL_ADMIN, $valid, 401, 'credential_expired');
        emailLogin($port, EMAIL_ADMIN, RESET_OLD_PASSWORD, 401, 'credentials_invalid');
        emailLogin($port, EMAIL_ADMIN, $loser['password'], 401, 'credentials_invalid');

        foreach ([$platformSms, $platformEmail] as $session) {
            sessionPlatform($port, $session['access_token'], 401, 'token_invalid');
        }
        smsExpect(smsRequest($port, 'GET', ACCOUNT_PROFILE, headers: emailBearer($orgEmail['access_token'])),
            401, 'token_invalid', 'Reset revoked the old organization access token');
        foreach ([$platformSms, $platformEmail, $orgEmail] as $session) {
            sessionRefresh($port, $session['refresh_token'], 401, 'token_invalid');
        }
        $fresh = sessionTokens(emailLogin($port, EMAIL_ADMIN, $winner['password'])['data']);
        sessionPlatform($port, $fresh['access_token']);
        // Put the account back in its pre-scenario state for the rollback test.
        emailPassword($port, $fresh['access_token'], RESET_OLD_PASSWORD);
    });
    echo "PASS wrong purpose, exact expiry, single concurrent reset, old password rejection, and account-wide revocation\n";
}

function testResetRechecksEmailUnderLock(Fixtures $fixtures, PDO $db, SmsLoginFixture $auth): void
{
    $auth->withHost('reset-email-epoch', static function (int $port) use ($fixtures, $db, $auth): void {
        $auth->clock(SMS_TIME);
        $challenge = resetChallenge($auth, $port, EMAIL_ADMIN);
        $rotate = $db->prepare('UPDATE starter_accounts SET email = ?, email_epoch = email_epoch + 1 WHERE email = ?');
        $db->beginTransaction();
        $client = null;
        $newEmail = 'rotated-reset@example.test';
        try {
            $rotate->execute([$newEmail, EMAIL_ADMIN]);
            clearstatcache(true, $fixtures->sqlLog);
            $offset = filesize($fixtures->sqlLog);
            check($offset !== false, 'Could not locate the reset race in the MySQL query log');
            $client = smsConnect($port, 'POST', RESET_COMPLETE, ['email' => EMAIL_ADMIN, 'password' => RESET_NEW_PASSWORD] + $challenge);
            $deadline = microtime(true) + 2;
            do {
                $log = file_get_contents($fixtures->sqlLog, false, null, $offset);
                check($log !== false, 'Could not read the reset race in the MySQL query log');
                if (preg_match('/from \x60starter_accounts\x60[^\r\n]*for update/i', $log) === 1) {
                    break;
                }
                check(microtime(true) < $deadline, 'Reset did not reach the held account lock');
                usleep(1_000);
            } while (true);
            $db->commit();
            smsExpect(smsReadResponse($client), 401, 'credential_expired', 'Reset rechecks the email after acquiring the account row lock');
            emailLogin($port, $newEmail, RESET_OLD_PASSWORD);
            $rotate->execute([EMAIL_ADMIN, $newEmail]);
            resetComplete($port, EMAIL_ADMIN, $challenge, 401, 'purpose_mismatch');
            emailLogin($port, EMAIL_ADMIN, RESET_OLD_PASSWORD);
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (is_resource($client)) {
                fclose($client);
            }
        }
    });
    echo "PASS reset rechecks the verified address under its account lock and rejects old proofs after an address changes back\n";
}

function testResetAuditRollback(PDO $db, SmsLoginFixture $auth): void
{
    $auth->withHost('reset-audit', static function (int $port) use ($db, $auth): void {
        $auth->clock(SMS_TIME);
        $session = sessionTokens(emailLogin($port, EMAIL_ADMIN, RESET_OLD_PASSWORD)['data']);
        $challenge = resetChallenge($auth, $port, EMAIL_ADMIN);
        $query = "SELECT actor_account_id, target_account_id, metadata FROM starter_audit_logs WHERE action = 'password_reset'";
        $db->exec("CREATE TRIGGER qa_reject_reset_audit BEFORE INSERT ON starter_audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'private audit failure detail'");
        try {
            resetComplete($port, EMAIL_ADMIN, $challenge, 503, 'audit_unavailable');
            sessionPlatform($port, $session['access_token']);
            sessionRefresh($port, $session['refresh_token']);
            emailLogin($port, EMAIL_ADMIN, RESET_NEW_PASSWORD, 401, 'credentials_invalid');
            emailLogin($port, EMAIL_ADMIN, RESET_OLD_PASSWORD);
        } finally {
            $db->exec('DROP TRIGGER qa_reject_reset_audit');
        }
        resetComplete($port, EMAIL_ADMIN, $challenge, 401, 'credential_expired');
        $audits = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
        $accountId = (int) $db->query("SELECT account_id FROM starter_platform_admins WHERE status = 'active'")->fetchColumn();
        check(count($audits) === 1 && (int) $audits[0]['actor_account_id'] === $accountId && (int) $audits[0]['target_account_id'] === $accountId
            && json_decode($audits[0]['metadata'], true, flags: JSON_THROW_ON_ERROR) === ['purpose' => 'reset'],
            'Only the winning reset persisted a redacted success audit');
    });
    echo "PASS failed reset audit rolls back password and session revocation while the proof stays spent\n";
}
