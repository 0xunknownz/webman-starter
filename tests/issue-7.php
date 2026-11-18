<?php

declare(strict_types=1);

namespace StarterAcceptance;

const EMAIL_SEND = '/saas/account/email/send';
const EMAIL_BIND = '/saas/account/email/bind';
const EMAIL_PASSWORD = '/saas/account/password';
const EMAIL_LOGIN = '/saas/auth/email/login';
const EMAIL_ADMIN = 'admin@example.test';

/** @return list<string> */
function emailBearer(string $token): array
{
    return ["Authorization: Bearer $token"];
}

/** @return list<array{email:string,code:string,purpose:string}> */
function emailMessages(SmsLoginFixture $fixture): array
{
    $path = $fixture->stateDirectory() . '/email-messages';
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    check($lines !== false, 'Could not read email transcript');

    return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
}

/** @return array{challenge_id:string,code:string} */
function emailChallenge(SmsLoginFixture $fixture, int $port, string $token, string $email): array
{
    $before = count(emailMessages($fixture));
    $body = smsExpect(smsRequest($port, 'POST', EMAIL_SEND, ['email' => $email], emailBearer($token)), 200, 'success', 'Send email');
    $id = $body['data']['challenge_id'] ?? null;
    check(is_string($id) && $id !== '' && $body['data'] === ['accepted' => true, 'challenge_id' => $id],
        'Email send did not expose the expected accepted response');
    $messages = emailMessages($fixture);
    check(count($messages) === $before + 1, 'Expected exactly one email submission');
    $submission = $messages[$before];
    check($submission['email'] === strtolower($email) && $submission['purpose'] === 'bind'
        && preg_match('/\A[0-9]{6}\z/', $submission['code']) === 1, 'Unexpected email recipient, purpose, or code format');

    return ['challenge_id' => $id, 'code' => $submission['code']];
}

function emailSubmit(int $port, string $token, string $email, int $status, string $code, array $headers = []): void
{
    smsExpect(smsRequest($port, 'POST', EMAIL_SEND, ['email' => $email], [...emailBearer($token), ...$headers]),
        $status, $code, 'Submit email challenge');
}

/** @param array{challenge_id:string,code:string} $challenge */
function emailBind(int $port, string $token, string $email, array $challenge, int $status = 200, string $code = 'success'): void
{
    smsExpect(smsRequest($port, 'POST', EMAIL_BIND, ['email' => $email] + $challenge, emailBearer($token)),
        $status, $code, 'Bind verified email');
}

function emailPassword(int $port, string $token, mixed $password, int $status = 200, string $code = 'success'): void
{
    smsExpect(smsRequest($port, 'PUT', EMAIL_PASSWORD, ['password' => $password], emailBearer($token)),
        $status, $code, 'Set password');
}

/** @return array<string,mixed> */
function emailLogin(int $port, string $email, mixed $password, int $status = 200, string $code = 'success', string $purpose = 'platform'): array
{
    return smsExpect(smsRequest($port, 'POST', EMAIL_LOGIN, compact('email', 'password', 'purpose')), $status, $code, 'Email password login');
}

function testEmailSignIn(Fixtures $fixtures, SmsLoginFixture $smsLogin): void
{
    $db = $fixtures->database('qa_email');
    migrate($fixtures);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $accountId = (int) $db->query("SELECT account_id FROM starter_platform_admins WHERE status = 'active'")->fetchColumn();
    $seed = $db->prepare('INSERT INTO starter_accounts (phone, created_at, updated_at) VALUES (?, NOW(), NOW())');
    $seed->execute(['+86' . SMS_MEMBER]);
    $seed->execute(['+86' . SMS_INVITED]);

    $smsLogin->withHost('email-sign-in', static function (int $port) use ($smsLogin, $db, $accountId): void {
        $smsLogin->clock(SMS_TIME);
        $token = smsToken(smsLogin($port, $smsLogin->issue($port)));
        smsExpect(smsRequest($port, 'POST', EMAIL_SEND, ['email' => EMAIL_ADMIN]), 401, 'unauthenticated', 'Email send requires Bearer');
        smsExpect(smsRequest($port, 'PUT', EMAIL_PASSWORD, ['password' => 'valid1234']), 401, 'unauthenticated', 'Password change requires Bearer');
        emailPassword($port, $token, 'valid1234', 403, 'forbidden');
        $before = count(emailMessages($smsLogin));
        foreach (['no-at.example.test', "a\n@example.test", "\"a\x7F\"@example.test", str_repeat('x', 254) . '@example.test'] as $invalid) {
            emailSubmit($port, $token, $invalid, 422, 'email_invalid');
        }
        check(count(emailMessages($smsLogin)) === $before, 'Invalid email reached the sender');
        $challenge = emailChallenge($smsLogin, $port, $token, strtoupper(EMAIL_ADMIN));
        emailBind($port, $token, EMAIL_ADMIN, $challenge);
        emailBind($port, $token, EMAIL_ADMIN, $challenge, 401, 'credential_expired');
        emailSubmit($port, $token, 'replacement@example.test', 403, 'forbidden');

        $disabled = emailLogin($port, EMAIL_ADMIN, 'valid1234', 401, 'credentials_invalid');
        $missing = emailLogin($port, 'missing@example.test', 'valid1234', 401, 'credentials_invalid');
        check(smsPublicShape($disabled) === smsPublicShape($missing), 'Email login discloses an account without a password');
        foreach (['', '1234567', str_repeat('a', 65), str_repeat('界', 25), str_repeat('　', 8), "abc\n12345", "abcd\u{0085}efgh", ['12345678']] as $invalid) {
            emailPassword($port, $token, $invalid, 422, 'validation_failed');
        }
        emailPassword($port, $token, '一二三四五六七八');
        $emailToken = smsToken(emailLogin($port, strtoupper(EMAIL_ADMIN), '一二三四五六七八')['data']);
        $me = smsExpect(smsRequest($port, 'GET', PLATFORM_ME, headers: emailBearer($emailToken)), 200, 'success', 'Email platform identity');
        check($me['data'] === ['account_id' => $accountId], 'Email login must address the existing SMS account');
        $orgToken = smsToken(smsExpect(smsRequest($port, 'POST', EMAIL_LOGIN, ['email' => EMAIL_ADMIN, 'password' => '一二三四五六七八']),
            200, 'success', 'Default email login purpose')['data']);
        smsExpect(smsRequest($port, 'GET', PLATFORM_ME, headers: emailBearer($orgToken)), 403, 'token_purpose_mismatch', 'Default email purpose is organization');
        $admin = $db->prepare('UPDATE starter_platform_admins SET status = ? WHERE account_id = ?');
        $admin->execute(['revoked', $accountId]);
        try {
            emailLogin($port, EMAIL_ADMIN, '一二三四五六七八', 403, 'forbidden');
        } finally {
            $admin->execute(['active', $accountId]);
        }

        $exactByteLimit = str_repeat('界', 24);
        emailPassword($port, $token, $exactByteLimit);
        emailLogin($port, EMAIL_ADMIN, $exactByteLimit);
        $password = str_repeat('x', 64);
        emailPassword($port, $token, $password);
        $invalid = emailLogin($port, EMAIL_ADMIN, $exactByteLimit, 401, 'credentials_invalid');
        check(smsPublicShape($missing) === smsPublicShape($invalid), 'Unknown address and wrong password must share the same response');
        emailLogin($port, EMAIL_ADMIN, $password); // Reset the failure count after a valid login.
        $wrongPassword = 'incorrect-password';
        for ($i = 0; $i < 4; ++$i) {
            emailLogin($port, $i % 2 ? strtoupper(EMAIL_ADMIN) : EMAIL_ADMIN, $wrongPassword, 401, 'credentials_invalid');
        }
        emailLogin($port, EMAIL_ADMIN, $wrongPassword, 429, 'password_login_locked');
        emailLogin($port, EMAIL_ADMIN, $password, 429, 'password_login_locked');
        emailLogin($port, 'missing@example.test', $password, 401, 'credentials_invalid');
        $smsLogin->clock(SMS_TIME + 60);
        smsToken(smsLogin($port, $smsLogin->issue($port)));
        $smsLogin->clock(SMS_TIME + 899);
        emailLogin($port, EMAIL_ADMIN, $password, 429, 'password_login_locked');
        $smsLogin->clock(SMS_TIME + 900);
        emailLogin($port, EMAIL_ADMIN, $password);
        $currentSession = sessionTokens(emailLogin($port, EMAIL_ADMIN, $password)['data']);
        sessionLogout($port, $currentSession['access_token']);
        emailPassword($port, $currentSession['access_token'], 'after-logout', 401, 'token_invalid');
        emailLogin($port, EMAIL_ADMIN, $password);
    });
    $stored = $db->query("SELECT email, email_epoch, email_verified_at, password_hash FROM starter_accounts WHERE id = $accountId")
        ->fetch(\PDO::FETCH_ASSOC);
    check($stored['email'] === EMAIL_ADMIN && (int) $stored['email_epoch'] === 1 && $stored['email_verified_at'] !== null
        && password_verify(str_repeat('x', 64), $stored['password_hash']), 'Verified email epoch and bcrypt hash must persist on the SMS account');
    $bound = $db->query("SELECT metadata FROM starter_audit_logs WHERE action = 'email_bound' AND target_account_id = $accountId")->fetchAll(\PDO::FETCH_COLUMN);
    check(count($bound) === 1 && json_decode($bound[0], true, flags: JSON_THROW_ON_ERROR) === ['purpose' => 'bind'],
        'Binding audit must be present and contain no credentials');
    echo "PASS authenticated email binding, one-time codes, Unicode password boundaries, opaque failures and isolated 15-minute lock\n";

    testEmailDeliveryAndChallenges($db, $smsLogin);
    testEmailOwnershipRace($fixtures, $db, $smsLogin);
}

function testEmailDeliveryAndChallenges(\PDO $db, SmsLoginFixture $fixture): void
{
    $fixture->withHost('email-delivery', static function (int $port) use ($fixture): void {
        $fixture->clock(SMS_TIME);
        $token = smsToken(smsLogin($port, $fixture->issue($port, SMS_MEMBER), phone: SMS_MEMBER, purpose: 'organization'));
        emailSubmit($port, $token, strtoupper(EMAIL_ADMIN), 409, 'email_already_used');
        $address = 'member@example.test';
        $before = count(emailMessages($fixture));
        $outcomeFile = $fixture->stateDirectory() . '/email-outcome';
        try {
            check(file_put_contents($outcomeFile, 'failed') !== false, 'Could not set fixture email outcome');
            emailSubmit($port, $token, $address, 502, 'email_submission_failed');
            $fixture->clock(SMS_TIME + 59);
            emailSubmit($port, $token, strtoupper($address), 429, 'email_rate_limited');
            $fixture->clock(SMS_TIME + 60);
            check(file_put_contents($outcomeFile, 'timeout') !== false, 'Could not set fixture email outcome');
            emailSubmit($port, $token, $address, 504, 'email_submission_timeout');
            emailSubmit($port, $token, 'other@example.test', 429, 'email_rate_limited', ['X-Forwarded-For: 192.0.2.15']);
            // The SMS channel still has room from the same source IP.
            $fixture->issue($port, SMS_INVITED);
        } finally {
            check(file_put_contents($outcomeFile, 'accepted') !== false, 'Could not restore fixture email outcome');
        }
        check(count(emailMessages($fixture)) === $before + 2, 'Rejected, timed out and throttled submissions used incorrect provider call counts');
    }, ['STARTER_EMAIL_PER_HOUR_PER_IP' => '2']);
    $outcomes = $db->query("SELECT action, metadata FROM starter_audit_logs WHERE action IN ('email_submission_failed', 'email_submission_timeout') ORDER BY id")
        ->fetchAll(\PDO::FETCH_ASSOC);
    check(array_column($outcomes, 'action') === ['email_submission_failed', 'email_submission_timeout'],
        'Email provider outcomes must be distinguishable in audit records');
    foreach ($outcomes as $outcome) {
        check(json_decode($outcome['metadata'], true, flags: JSON_THROW_ON_ERROR) === ['purpose' => 'bind'],
            'Email provider outcome audits must omit credentials and address');
    }

    $fixture->withHost('email-challenges', static function (int $port) use ($fixture): void {
        $fixture->clock(SMS_TIME);
        $token = smsToken(smsLogin($port, $fixture->issue($port, SMS_MEMBER), phone: SMS_MEMBER, purpose: 'organization'));
        $other = smsToken(smsLogin($port, $fixture->issue($port, SMS_INVITED), phone: SMS_INVITED, purpose: 'organization'));
        $address = 'member@example.test';
        $first = emailChallenge($fixture, $port, $token, $address);
        emailBind($port, $other, $address, $first, 401, 'purpose_mismatch');
        emailBind($port, $token, 'other@example.test', $first, 401, 'purpose_mismatch');
        $fixture->clock(SMS_TIME + 60);
        $replacement = emailChallenge($fixture, $port, $token, $address);
        emailBind($port, $token, $address, $first, 401, 'credential_expired');
        $wrong = ['code' => wrongSmsCode($replacement['code'])] + $replacement;
        for ($i = 0; $i < 4; ++$i) {
            emailBind($port, $token, $address, $wrong, 401, 'verification_code_invalid');
        }
        emailBind($port, $token, $address, $wrong, 401, 'verification_attempts_exceeded');
        emailBind($port, $token, $address, $replacement, 401, 'credential_expired');
        $fixture->clock(SMS_TIME + 120);
        $expires = emailChallenge($fixture, $port, $token, $address);
        $fixture->clock(SMS_TIME + 719);
        emailBind($port, $token, $address, ['code' => wrongSmsCode($expires['code'])] + $expires, 401, 'verification_code_invalid');
        $fixture->clock(SMS_TIME + 720);
        emailBind($port, $token, $address, $expires, 401, 'credential_expired');
    });
    $fixture->withHost('email-day', static function (int $port) use ($fixture): void {
        // Midnight in Asia/Shanghai. The sliding interval has already elapsed by midnight.
        $midnight = gmmktime(16, 0, 0, 1, 2, 2026);
        $fixture->clock($midnight - 660);
        $token = smsToken(smsLogin($port, $fixture->issue($port, SMS_MEMBER), phone: SMS_MEMBER, purpose: 'organization'));
        for ($i = 0; $i < 10; ++$i) {
            $fixture->clock($midnight - 660 + $i * 60);
            emailChallenge($fixture, $port, $token, 'member@example.test');
        }
        $fixture->clock($midnight - 1);
        emailSubmit($port, $token, 'member@example.test', 429, 'email_rate_limited');
        $fixture->clock($midnight);
        emailChallenge($fixture, $port, $token, 'member@example.test');
    });
    echo "PASS provider rejection, timeout, channel-separated IP limits, daily email quota, expiry and five-try challenges\n";
}

function testEmailOwnershipRace(Fixtures $fixtures, \PDO $db, SmsLoginFixture $fixture): void
{
    $serverConfig = "$fixtures->host/config/server.php";
    $original = file_get_contents($serverConfig);
    check($original !== false, 'Could not read disposable server configuration');
    check(file_put_contents($serverConfig . '.original.inc', $original) !== false, 'Could not preserve disposable server configuration');
    $config = <<<'PHP'
        <?php
        $config = require __DIR__ . '/server.php.original.inc';
        $port = getenv('WEBMAN_STARTER_HTTP_PORT');
        $config['pid_file'] .= '.' . $port;
        $config['status_file'] .= '.' . $port;
        return $config;
        PHP;
    check(file_put_contents($serverConfig, $config) !== false, 'Could not separate race host process files');
    try {
        testEmailConcurrentBinding($fixture);
    } finally {
        check(file_put_contents($serverConfig, $original) !== false, 'Could not restore disposable server configuration');
    }
    check((int) $db->query("SELECT COUNT(*) FROM starter_accounts WHERE email = 'race@example.test' AND email_epoch = 1")->fetchColumn() === 1,
        'Concurrent claims must persist a single owner');
    check((int) $db->query('SELECT COUNT(*) FROM starter_accounts')->fetchColumn() === 3, 'Email binding and login must never create or merge accounts');
    echo "PASS SQL email ownership under concurrent binding requests\n";
}

function testEmailConcurrentBinding(SmsLoginFixture $fixture): void
{
    $fixture->withHost('email-race', static function (int $port) use ($fixture): void {
        $fixture->clock(SMS_TIME);
        $firstToken = smsToken(smsLogin($port, $fixture->issue($port, SMS_MEMBER), phone: SMS_MEMBER, purpose: 'organization'));
        $secondToken = smsToken(smsLogin($port, $fixture->issue($port, SMS_INVITED), phone: SMS_INVITED, purpose: 'organization'));
        $address = 'race@example.test';
        $first = emailChallenge($fixture, $port, $firstToken, $address);
        $fixture->clock(SMS_TIME + 60);
        $second = emailChallenge($fixture, $port, $secondToken, strtoupper($address));
        // Each host has one worker and shares the database, Redis namespace, and clock gate.
        $fixture->withHost('email-race', static function (int $otherPort) use ($fixture, $port, $address, $first, $second, $firstToken, $secondToken): void {
            $gate = 'email-race-' . bin2hex(random_bytes(8));
            $fixture->clock(SMS_TIME + 60, $gate);
            $clients = [];
            try {
                $clients[] = smsConnect($port, 'POST', EMAIL_BIND, ['email' => $address] + $first, emailBearer($firstToken));
                $clients[] = smsConnect($otherPort, 'POST', EMAIL_BIND, ['email' => $address] + $second, emailBearer($secondToken));
                smsRaceResults(array_map(smsReadResponse(...), $clients), ['200:success', '409:email_already_used']);
                check(count(glob($fixture->stateDirectory() . "/$gate-*")) === 2, 'Email ownership race did not reach both workers');
            } finally {
                foreach ($clients as $client) {
                    fclose($client);
                }
                $fixture->clock(SMS_TIME + 60);
            }
        }, ['WEBMAN_STARTER_HTTP_WORKERS' => '1']);
    }, ['WEBMAN_STARTER_HTTP_WORKERS' => '1']);
}
