<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

const SMS_SEND = '/saas/auth/sms/send';
const SMS_LOGIN = '/saas/auth/sms/login';
const PLATFORM_ME = '/saas/platform/me';
const SMS_TIME = 1767268770; // 2026-01-01 11:59:30 UTC, 19:59:30 Asia/Shanghai
const SMS_MEMBER = '13800138001';
const SMS_INVITED = '13800138002';
const SMS_EXPIRED_INVITE = '13800138003';
const SMS_UNKNOWN = '13800138004';

final class SmsLoginFixture
{
    private readonly string $state;

    public function __construct(private readonly Fixtures $fixtures)
    {
        $this->state = "$fixtures->dir/sms";
        check(mkdir($this->state, 0700), 'Could not create SMS state directory');
        check(copy("$fixtures->host/config/starter.php", "$fixtures->host/config/starter.original.inc"),
            'Could not preserve disposable host starter config');
        copyTree(__DIR__ . '/host', "$fixtures->host/qa-sms");
        check(copy(__DIR__ . '/host/starter.php', "$fixtures->host/config/starter.php"),
            'Could not inject disposable host SMS fixtures');
        $this->clock(SMS_TIME);
        $this->outcome('accepted');
        check(file_put_contents("$this->state/messages", '') === 0, 'Could not create SMS transcript');
    }

    public function stateDirectory(): string
    {
        return $this->state;
    }

    /** @param array<string,string> $env */
    public function start(int $port, string $prefix, array $env = []): Process
    {
        return $this->fixtures->start([PHP_BINARY, 'start.php', 'start'], $this->fixtures->host, $env + [
            'WEBMAN_STARTER_HTTP_PORT' => (string) $port,
            'WEBMAN_STARTER_HTTP_WORKERS' => '2',
            'WEBMAN_STARTER_ROUTES_ENABLED' => '1',
            'STARTER_AUTH_ENABLED' => '1',
            'STARTER_AUTH_SECRET' => str_repeat('qa01-sms-fixture-', 3),
            'STARTER_REDIS_PREFIX' => str_contains($prefix, ':') ? $prefix : "qa01:$prefix",
            'QA_SMS_STATE' => $this->state,
        ]);
    }

    /** @param array<string,string> $env */
    public function withHost(string $prefix, callable $test, array $env = []): void
    {
        $port = $this->fixtures->port();
        $server = $this->start($port, $prefix, $env);
        try {
            $this->fixtures->ready($server, static fn (): bool => httpGet($port, '/saas/health')[0] === 200,
                "Webman for SMS case $prefix");
            $test($port);
        } finally {
            $server->stop();
        }
    }

    public function clock(int $timestamp, string $gate = ''): void
    {
        check(file_put_contents("$this->state/clock", "$timestamp $gate", LOCK_EX) !== false, 'Could not set SMS clock');
    }

    public function outcome(string $outcome): void
    {
        check(file_put_contents("$this->state/outcome", $outcome) !== false, 'Could not set SMS sender result');
    }

    /** @return list<array{phone:string,code:string,purpose:string}> */
    public function messages(): array
    {
        $lines = file("$this->state/messages", FILE_IGNORE_NEW_LINES);
        check($lines !== false, 'Could not read SMS transcript');

        return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
    }

    /** @return array{challenge_id:string,code:string} */
    public function issue(int $port, string $phone = PHONE): array
    {
        $before = count($this->messages());
        $challenge = smsSend($port, $phone)['data']['challenge_id'];
        $messages = $this->messages();
        check(count($messages) === $before + 1, 'Expected one captured SMS submission');
        $sms = $messages[$before];
        check($sms['phone'] === (str_starts_with($phone, '+86') ? $phone : '+86' . $phone)
            && $sms['purpose'] === 'login' && preg_match('/\A\d{6}\z/', $sms['code']) === 1,
            'SMS sender received the wrong destination, purpose, or code format');

        return ['challenge_id' => $challenge, 'code' => $sms['code']];
    }

    /** @param list<array<string,mixed>> $requests @return list<array{int,array<string,mixed>}> */
    public function race(int $port, int $time, string $path, array $requests): array
    {
        $gate = 'race-' . bin2hex(random_bytes(8));
        $this->clock($time, $gate);
        $clients = [];
        try {
            foreach ($requests as $index => $data) {
                $clients[] = smsConnect($port, 'POST', $path, $data);
                if ($index === 0) {
                    // Wait for the first worker to enter the gate before connecting to the idle worker.
                    $deadline = microtime(true) + 2;
                    while (glob("$this->state/$gate-*") === []) {
                        check(microtime(true) < $deadline, 'First Webman worker did not enter the race gate');
                        usleep(1_000);
                    }
                }
            }
            $responses = array_map(smsReadResponse(...), $clients);
            check(count(glob("$this->state/$gate-*")) === 2, 'Race requests must reach the two Webman workers');

            return $responses;
        } finally {
            foreach ($clients as $client) {
                fclose($client);
            }
            $this->clock($time);
        }
    }
}

/** @param array<string,mixed>|null $data @param list<string> $headers @return resource */
function smsConnect(int $port, string $method, string $path, ?array $data = null, array $headers = [])
{
    $stream = stream_socket_client("tcp://127.0.0.1:$port", timeout: 1);
    check(is_resource($stream), 'Could not connect to disposable SMS host');
    stream_set_timeout($stream, 6);
    $payload = $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR);
    $request = implode("\r\n", ["$method $path HTTP/1.1", "Host: 127.0.0.1:$port", 'Connection: close',
        'Accept: application/json', 'Content-Type: application/json', 'Content-Length: ' . strlen($payload), ...$headers,
    ]) . "\r\n\r\n" . $payload;
    if (fwrite($stream, $request) !== strlen($request)) {
        fclose($stream);
        check(false, "Could not write $method $path");
    }

    return $stream;
}

/** @param resource $stream @return array{int,array<string,mixed>} */
function smsReadResponse($stream): array
{
    $response = stream_get_contents($stream);
    check($response !== false && !stream_get_meta_data($stream)['timed_out'], 'SMS HTTP response timed out');
    $parts = explode("\r\n\r\n", $response, 2);
    check(count($parts) === 2 && preg_match('/\AHTTP\/\S+ (\d{3})/', $parts[0], $status) === 1,
        'Missing SMS HTTP status or body');
    [$headers, $raw] = $parts;
    check(preg_match('/^Content-Type: application\/json\b/im', $headers) === 1, "Expected SMS JSON (HTTP {$status[1]})");
    $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    check(is_array($body) && is_string($body['code'] ?? null) && is_string($body['message'] ?? null)
        && array_key_exists('data', $body), 'Expected the shared API envelope');
    check(preg_match('/^X-Trace-Id:\s*([^\r\n ]+)/im', $headers, $trace) === 1
        && $trace[1] === ($body['trace_id'] ?? null), 'SMS HTTP trace header and envelope differ');

    return [(int) $status[1], $body];
}

/** @param array{int,array<string,mixed>} $response @return array<string,mixed> */
function smsExpect(array $response, int $status, string $code, string $label): array
{
    [$actualStatus, $body] = $response;
    check($actualStatus === $status && $body['code'] === $code,
        "$label expected HTTP $status $code, received HTTP $actualStatus {$body['code']}");

    return $body;
}

/** @param array<string,mixed>|null $data @param list<string> $headers @return array{int,array<string,mixed>} */
function smsRequest(int $port, string $method, string $path, ?array $data = null, array $headers = []): array
{
    $stream = smsConnect($port, $method, $path, $data, $headers);
    try {
        return smsReadResponse($stream);
    } finally {
        fclose($stream);
    }
}

/** @param list<string> $headers @return array<string,mixed> */
function smsSend(int $port, string $phone, int $status = 200, string $code = 'success', array $headers = []): array
{
    $body = smsExpect(smsRequest($port, 'POST', SMS_SEND, ['phone' => $phone], $headers), $status, $code, 'Send SMS');
    if ($status === 200) {
        $data = $body['data'];
        check($body['message'] === '短信请求已受理' && is_string($data['challenge_id'] ?? null) && $data['challenge_id'] !== ''
            && $data == ['accepted' => true, 'challenge_id' => $data['challenge_id']] && $data['accepted'] === true,
            'Public SMS send did not return the common accepted response');
    }

    return $body;
}

/** @param array{challenge_id:string,code:string} $challenge @return array<string,mixed> */
function smsLogin(int $port, array $challenge, int $status = 200, string $code = 'success', string $phone = PHONE,
    string $purpose = 'platform'): array
{
    return smsExpect(smsRequest($port, 'POST', SMS_LOGIN, ['phone' => $phone, 'purpose' => $purpose] + $challenge),
        $status, $code, 'SMS login')['data'] ?? [];
}

function smsToken(array $loginData): string
{
    $token = $loginData['access_token'] ?? null;
    check(is_string($token) && $token !== '', 'Successful SMS login did not issue an access token');

    return $token;
}

function wrongSmsCode(string $code): string
{
    return $code === '000000' ? '111111' : '000000';
}

/** @param array<string,mixed> $body @return array<string,mixed> */
function smsPublicShape(array $body): array
{
    unset($body['trace_id'], $body['data']['challenge_id']);

    return $body;
}

function testSmsDelivery(SmsLoginFixture $qa, PDO $db): void
{
    $db->exec("INSERT INTO starter_organizations (name, subscription_expires_at) VALUES ('QA pending owner', '2026-02-01 00:00:00')");
    $organization = $db->lastInsertId();
    $invite = $db->prepare("INSERT INTO starter_invitations (organization_id, phone, expires_at, status) VALUES (?, ?, ?, 'pending')");
    $invite->execute([$organization, '+86' . SMS_INVITED, '2026-01-08 00:00:00']);
    $invite->execute([$organization, '+86' . SMS_EXPIRED_INVITE, gmdate('Y-m-d H:i:s', SMS_TIME)]);
    $pending = $db->query('SELECT * FROM starter_invitations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $accountCount = $db->query('SELECT COUNT(*) FROM starter_accounts')->fetchColumn();

    $qa->withHost('delivery', static function (int $port) use ($qa, $db): void {
        $qa->clock(SMS_TIME);
        $before = count($qa->messages());
        foreach (['01088888888', '+12025550123', '123'] as $invalid) {
            smsSend($port, $invalid, 422, 'phone_invalid');
        }
        $skipped = smsSend($port, SMS_UNKNOWN);
        $expired = smsSend($port, SMS_EXPIRED_INVITE);
        check(count($qa->messages()) === $before, 'Ineligible numbers must not reach the SMS provider');
        $invalidProof = ['challenge_id' => $skipped['data']['challenge_id'], 'code' => 'invalid'];
        smsLogin($port, $invalidProof, 401, 'verification_code_invalid', SMS_UNKNOWN, 'organization');
        $known = smsSend($port, '+86' . PHONE);
        $invited = $qa->issue($port, SMS_INVITED);
        smsLogin($port, $invited, 401, 'unauthenticated', SMS_INVITED, 'organization');
        $qa->outcome('failed');
        try {
            $failed = smsSend($port, SMS_MEMBER);
            $unknownThrottled = smsSend($port, SMS_UNKNOWN, 429, 'sms_rate_limited');
            $failedThrottled = smsSend($port, SMS_MEMBER, 429, 'sms_rate_limited');
            $qa->clock(SMS_TIME + 60);
            $qa->outcome('timeout');
            $timedOut = smsSend($port, SMS_INVITED);
            $timeoutThrottled = smsSend($port, '+86' . SMS_INVITED, 429, 'sms_rate_limited');
            foreach ([$expired, $known, $failed, $timedOut] as $response) {
                check(smsPublicShape($response) === smsPublicShape($skipped), 'Public SMS response reveals delivery or registration');
            }
            foreach ([$failedThrottled, $timeoutThrottled] as $response) {
                check(smsPublicShape($response) === smsPublicShape($unknownThrottled),
                    'Public SMS throttle response reveals registration or provider outcome');
            }
        } finally {
            $qa->outcome('accepted');
        }
        $submissions = array_slice($qa->messages(), $before);
        check(array_column($submissions, 'phone') === ['+86' . PHONE, '+86' . SMS_INVITED, '+86' . SMS_MEMBER, '+86' . SMS_INVITED],
            'SMS provider call count or normalized destinations were incorrect');
        $audit = $db->query("SELECT action, metadata FROM starter_audit_logs
            WHERE action IN ('sms_submission_failed','sms_submission_timeout') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        check(array_column($audit, 'action') === ['sms_submission_failed', 'sms_submission_timeout'], 'Distinct provider outcomes must be audited');
        foreach ($audit as $index => $row) {
            $encoded = $row['metadata'];
            check(is_string($encoded) && $encoded !== '' && !str_contains($encoded, 'delivered'),
                'Failure or timeout audit is missing or records delivery');
            foreach ($submissions as $sms) {
                check(!str_contains($encoded, substr($sms['phone'], 3))
                    && !str_contains($encoded, $sms['code']), 'Restricted SMS audit contains plaintext phone or code');
            }
            check(!str_contains($encoded, [$failed, $timedOut][$index]['data']['challenge_id']),
                'Restricted SMS audit contains a raw challenge identifier');
        }
    });
    check($db->query('SELECT * FROM starter_invitations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $pending,
        'SMS send or login modified a pending invitation or its reserved seat');
    check($db->query('SELECT COUNT(*) FROM starter_accounts')->fetchColumn() === $accountCount,
        'SMS login created an account');
    echo "PASS SMS eligibility, opaque send responses, provider failure/timeout audits and pending invite invariants\n";
}

function testSmsPhoneLimits(SmsLoginFixture $qa): void
{
    // These Unix instants cross midnight in Shanghai and UTC respectively.
    foreach (['shanghai' => gmmktime(16, 0, 0, 1, 2, 2026), 'utc' => gmmktime(0, 0, 0, 1, 3, 2026)] as $case => $midnight) {
        $qa->withHost($case, static function (int $port) use ($qa, $midnight): void {
            $qa->clock($midnight - 660);
            $before = count($qa->messages());
            $qa->issue($port);
            $qa->clock($midnight - 601);
            smsSend($port, '+86' . PHONE, 429, 'sms_rate_limited');
            $qa->clock($midnight - 600);
            $qa->outcome('failed');
            try {
                $qa->issue($port, '+86' . PHONE);
                $qa->outcome('timeout');
                for ($i = 2; $i < 10; ++$i) {
                    $qa->clock($midnight - 660 + 60 * $i);
                    $qa->issue($port);
                }
            } finally {
                $qa->outcome('accepted');
            }
            $qa->clock($midnight - 1);
            smsSend($port, '+86' . PHONE, 429, 'sms_rate_limited');
            check(count($qa->messages()) === $before + 10, 'Daily and interval quota rejections reached the provider');
            $qa->clock($midnight);
            $qa->issue($port);
            check(count($qa->messages()) === $before + 11, 'Midnight failed to release the daily SMS quota');
        }, $case === 'utc' ? ['STARTER_TIMEZONE' => 'UTC'] : []);
    }
    echo "PASS normalized 59/60-second spacing and 10/11 daily quota with Asia/Shanghai and UTC boundaries\n";
}

function testSmsIpLimits(SmsLoginFixture $qa): void
{
    // Begin shortly before a fixed-hour boundary, then make requests on both sides of that boundary.
    $qa->withHost('ip-default', static function (int $port) use ($qa): void {
        $start = gmmktime(12, 59, 30, 1, 1, 2026);
        $before = count($qa->messages());
        $qa->clock($start);
        smsSend($port, '13800140100');
        $qa->clock($start + 35);
        $qa->outcome('failed');
        try {
            $qa->issue($port);
            $qa->outcome('timeout');
            $qa->issue($port, SMS_MEMBER);
        } finally {
            $qa->outcome('accepted');
        }
        for ($i = 3; $i < 20; ++$i) {
            smsSend($port, (string) (13800140100 + $i), headers: ["X-Forwarded-For: 198.51.100.$i", "X-Real-IP: 192.0.2.$i"]);
        }
        $qa->clock($start + 3599);
        smsSend($port, '13800140120', 429, 'sms_rate_limited', ['X-Forwarded-For: 198.51.100.250', 'X-Real-IP: 192.0.2.250']);
        $qa->clock($start + 3600);
        smsSend($port, '13800140121');
        smsSend($port, '13800140122', 429, 'sms_rate_limited');
        check(count($qa->messages()) === $before + 2, 'Unknown numbers at the IP limit must not be submitted');
    });
    $qa->withHost('ip-maximum', static function (int $port) use ($qa): void {
        $qa->clock(SMS_TIME);
        for ($i = 0; $i < 200; ++$i) {
            smsSend($port, (string) (13800141000 + $i));
        }
        smsSend($port, '13800141200', 429, 'sms_rate_limited');
    }, ['STARTER_SMS_PER_HOUR_PER_IP' => '200']);
    $qa->withHost('ip-trusted', static function (int $port) use ($qa): void {
        $qa->clock(SMS_TIME);
        smsSend($port, '13800141300', headers: ['X-Forwarded-For: 192.0.2.1, 198.51.100.1, 127.0.0.1']);
        smsSend($port, '13800141301', 429, 'sms_rate_limited', ['X-Forwarded-For: 192.0.2.2, 198.51.100.1, 127.0.0.1']);
        smsSend($port, '13800141302', headers: ['X-Forwarded-For: 192.0.2.2, 198.51.100.2, 127.0.0.1']);
    }, ['STARTER_SMS_PER_HOUR_PER_IP' => '1', 'STARTER_TRUSTED_PROXIES' => '127.0.0.1']);
    echo "PASS default and configured IP boundaries, sliding-hour expiry, spoofed and trusted proxy headers\n";
}

function testSmsLoginAndPlatform(SmsLoginFixture $qa, PDO $db, int $accountId): void
{
    $qa->withHost('platform', static function (int $port) use ($qa, $db, $accountId): void {
        $qa->clock(SMS_TIME);
        smsExpect(smsRequest($port, 'GET', PLATFORM_ME), 401, 'unauthenticated', 'Platform without Bearer');
        smsExpect(smsRequest($port, 'GET', PLATFORM_ME, headers: ['Authorization: Bearer invalid']), 401,
            'token_invalid', 'Platform with invalid Bearer');
        $challenge = $qa->issue($port);
        smsLogin($port, $challenge, 401, 'purpose_mismatch', SMS_MEMBER);
        $wrongPurpose = smsRequest($port, 'POST', SMS_LOGIN, ['phone' => PHONE, 'purpose' => 'invitation'] + $challenge);
        smsExpect($wrongPurpose, 422, 'validation_failed', 'SMS login with unsupported token purpose');
        $token = smsToken(smsLogin($port, $challenge, phone: '+86' . PHONE));
        $bearer = ["Authorization: Bearer $token"];
        $me = smsExpect(smsRequest($port, 'GET', PLATFORM_ME, headers: $bearer), 200, 'success', 'Platform account identity');
        check($me['data'] === ['account_id' => $accountId], 'Platform returned the wrong account or additional identity fields');
        smsLogin($port, $challenge, 401, 'credential_expired');

        $qa->clock(SMS_TIME + 60);
        $organizationChallenge = $qa->issue($port);
        $orgResult = smsExpect(smsRequest($port, 'POST', SMS_LOGIN, ['phone' => PHONE] + $organizationChallenge),
            200, 'success', 'SMS login with default purpose');
        $orgToken = smsToken($orgResult['data']);
        smsExpect(smsRequest($port, 'GET', PLATFORM_ME, headers: ["Authorization: Bearer $orgToken"]),
            403, 'token_purpose_mismatch', 'Platform with organization Bearer');
        $ordinary = $qa->issue($port, SMS_MEMBER);
        smsLogin($port, $ordinary, 403, 'forbidden', SMS_MEMBER);

        $updateRole = $db->prepare('UPDATE starter_platform_admins SET status = ? WHERE account_id = ?');
        $updateRole->execute(['revoked', $accountId]);
        try {
            smsExpect(smsRequest($port, 'GET', PLATFORM_ME, headers: $bearer), 403, 'forbidden', 'Previously issued Bearer after revocation');
        } finally {
            $updateRole->execute(['active', $accountId]);
        }
    });
    echo "PASS existing account SMS platform login, one-time consumption, token purpose and current qualification checks\n";
}

function testSmsChallenges(SmsLoginFixture $qa, PDO $db): void
{
    $qa->withHost('challenges', static function (int $port) use ($qa, $db): void {
        $qa->clock(SMS_TIME);
        $valid = $qa->issue($port);
        $expired = $qa->issue($port, SMS_MEMBER);
        $forged = ['challenge_id' => str_repeat('f', 32), 'code' => $valid['code']];
        smsLogin($port, $forged, 401, 'credential_expired');
        $wrong = ['code' => wrongSmsCode($valid['code'])] + $valid;
        for ($i = 0; $i < 4; ++$i) {
            smsLogin($port, $wrong, 401, 'verification_code_invalid');
        }
        $qa->clock(SMS_TIME + 299);
        smsToken(smsLogin($port, $valid));
        smsLogin($port, $valid, 401, 'credential_expired');
        $qa->clock(SMS_TIME + 300);
        smsLogin($port, $expired, 401, 'credential_expired', SMS_MEMBER, 'organization');

        $exhaust = $qa->issue($port);
        $wrong = ['code' => wrongSmsCode($exhaust['code'])] + $exhaust;
        for ($i = 0; $i < 4; ++$i) {
            smsLogin($port, $wrong, 401, 'verification_code_invalid');
        }
        smsLogin($port, $wrong, 401, 'verification_attempts_exceeded');
        smsLogin($port, $exhaust, 401, 'credential_expired');
        $qa->clock(SMS_TIME + 301);
        smsSend($port, PHONE, 429, 'sms_rate_limited');
        smsLogin($port, $exhaust, 401, 'credential_expired');

        $qa->clock(SMS_TIME + 360);
        $old = $qa->issue($port);
        $beforeAudit = (int) $db->query("SELECT COUNT(*) FROM starter_audit_logs WHERE action = 'sms_challenge_reissued'")->fetchColumn();
        $qa->clock(SMS_TIME + 420);
        $replacement = $qa->issue($port);
        check($replacement['challenge_id'] !== $old['challenge_id'], 'A resend failed to replace the prior challenge');
        smsLogin($port, $old, 401, 'credential_expired');
        smsToken(smsLogin($port, $replacement));
        check((int) $db->query("SELECT COUNT(*) FROM starter_audit_logs WHERE action = 'sms_challenge_reissued'")->fetchColumn() === $beforeAudit + 1,
            'Accepted challenge replacement was not audited');
    });
    $qa->withHost('strict-attempts', static function (int $port) use ($qa): void {
        $qa->clock(SMS_TIME);
        $valid = $qa->issue($port);
        $wrong = ['code' => wrongSmsCode($valid['code'])] + $valid;
        smsLogin($port, $wrong, 401, 'verification_code_invalid');
        smsLogin($port, $wrong, 401, 'verification_attempts_exceeded');
        smsLogin($port, $valid, 401, 'credential_expired');
    }, ['STARTER_SMS_ATTEMPTS' => '2']);
    echo "PASS exact 300-second expiry, five failed attempts, configured attempts and challenge replacement\n";
}

/** @param list<array{int,array<string,mixed>}> $responses @param list<string> $expected */
function smsRaceResults(array $responses, array $expected): void
{
    $results = array_map(static fn (array $response): string => $response[0] . ':' . $response[1]['code'], $responses);
    sort($results);
    sort($expected);
    check($results === $expected, 'SMS race outcomes differed from expected outcomes: ' . implode(', ', $results));
}

function testSmsRaces(SmsLoginFixture $qa): void
{
    $qa->withHost('races', static function (int $port) use ($qa): void {
        $qa->clock(SMS_TIME);
        $before = count($qa->messages());
        $sends = $qa->race($port, SMS_TIME, SMS_SEND, [['phone' => PHONE], ['phone' => '+86' . PHONE]]);
        smsRaceResults($sends, ['200:success', '429:sms_rate_limited']);
        check(count($qa->messages()) === $before + 1, 'Concurrent sends submitted more than once');

        for ($i = 0; $i < 18; ++$i) {
            smsSend($port, (string) (13800141400 + $i));
        }
        smsRaceResults($qa->race($port, SMS_TIME, SMS_SEND, [['phone' => '13800141418'], ['phone' => '13800141419']]),
            ['200:success', '429:sms_rate_limited']);

        $time = SMS_TIME + 3600;
        $qa->clock($time);
        $login = ['phone' => PHONE, 'purpose' => 'platform'] + $qa->issue($port);
        $results = $qa->race($port, $time, SMS_LOGIN, [$login, $login]);
        smsRaceResults($results, ['200:success', '401:credential_expired']);
        foreach ($results as [$status, $body]) {
            if ($status === 200) {
                smsToken($body['data']);
            }
        }

        $qa->clock($time + 60);
        $login = ['phone' => PHONE, 'purpose' => 'platform'] + $qa->issue($port);
        $wrong = ['code' => wrongSmsCode($login['code'])] + $login;
        for ($i = 0; $i < 3; ++$i) {
            smsExpect(smsRequest($port, 'POST', SMS_LOGIN, $wrong), 401, 'verification_code_invalid', 'Prime wrong-code race');
        }
        smsRaceResults($qa->race($port, $time + 60, SMS_LOGIN, [$wrong, $wrong, $wrong]),
            ['401:verification_code_invalid', '401:verification_attempts_exceeded', '401:credential_expired']);
        smsLogin($port, $login, 401, 'credential_expired');

        $qa->clock($time + 120);
        $login = ['phone' => PHONE, 'purpose' => 'platform'] + $qa->issue($port);
        $wrong = ['code' => wrongSmsCode($login['code'])] + $login;
        for ($i = 0; $i < 4; ++$i) {
            smsExpect(smsRequest($port, 'POST', SMS_LOGIN, $wrong), 401, 'verification_code_invalid', 'Prime exhaustion race');
        }
        $race = $qa->race($port, $time + 120, SMS_LOGIN, [$login, $wrong]);
        smsRaceResults($race, $race[0][0] === 200
            ? ['200:success', '401:credential_expired']
            : ['401:credential_expired', '401:verification_attempts_exceeded']);
        smsLogin($port, $login, 401, 'credential_expired');
    });
    echo "PASS two-worker atomic phone and IP quotas, duplicate consume, parallel failures and success versus exhaustion\n";
}


function testSmsNamespaces(SmsLoginFixture $qa): void
{
    $qa->clock(SMS_TIME);
    $challenge = [];
    $env = ['STARTER_SMS_PER_HOUR_PER_IP' => '1'];
    $qa->withHost('product-a:production', static function (int $port) use ($qa, &$challenge): void {
        $challenge = $qa->issue($port);
    }, $env);
    foreach (['product-b:production', 'product-a:test'] as $prefix) {
        $qa->withHost($prefix, static function (int $port) use ($qa, $challenge): void {
            smsLogin($port, $challenge, 401, 'credential_expired');
            $qa->issue($port);
        }, $env);
    }
    $qa->withHost('product-a:production', static function (int $port) use ($challenge): void {
        smsSend($port, PHONE, 429, 'sms_rate_limited');
        smsToken(smsLogin($port, $challenge));
    }, $env);
    echo "PASS shared state for one namespace and independent quotas and challenges across products and environments\n";
}

function testSmsRateConfig(Fixtures $fixtures, SmsLoginFixture $qa): void
{
    foreach ([
        ['STARTER_SMS_PER_HOUR_PER_IP' => '201'],
        ['STARTER_SMS_PER_HOUR_PER_IP' => '0'],
        ['STARTER_SMS_ENABLED' => '0'],
        ['STARTER_SMS_INTERVAL' => '59'],
        ['STARTER_SMS_PER_DAY' => '11'],
    ] as $env) {
        $port = $fixtures->port();
        $server = $qa->start($port, 'invalid-config', $env);
        try {
            [$exit, $output] = $server->result(5);
            $name = array_key_first($env);
            check($exit !== 0 && str_contains($output, 'rate_limit_config_invalid') && httpGet($port, '/saas/health')[0] === 0,
                "Invalid $name must reject Webman startup with rate_limit_config_invalid");
        } finally {
            $server->stop();
        }
    }
    $qa->withHost('strict-limits', static function (int $port) use ($qa): void {
        $qa->clock(SMS_TIME);
        $qa->issue($port);
        $qa->clock(SMS_TIME + 60);
        smsSend($port, PHONE, 429, 'sms_rate_limited');
        $qa->clock(SMS_TIME + 61);
        $qa->issue($port);
        $qa->clock(SMS_TIME + 122);
        smsSend($port, PHONE, 429, 'sms_rate_limited');
    }, ['STARTER_SMS_INTERVAL' => '61', 'STARTER_SMS_PER_DAY' => '2']);
    echo "PASS stricter phone policy and process startup rejection of invalid or disabled SMS limits\n";
}

function testSmsLogin(Fixtures $fixtures, SmsLoginFixture $qa): void
{
    $db = $fixtures->database('qa_sms');
    migrate($fixtures);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $accountId = (int) $db->query("SELECT account_id FROM starter_platform_admins WHERE status = 'active'")->fetchColumn();
    check($accountId > 0, 'Could not seed SMS platform administrator');
    $db->prepare('INSERT INTO starter_accounts (phone, created_at, updated_at) VALUES (?, NOW(), NOW())')
        ->execute(['+86' . SMS_MEMBER]);

    testSmsLoginAndPlatform($qa, $db, $accountId);
    testSmsDelivery($qa, $db);
    testSmsPhoneLimits($qa);
    testSmsIpLimits($qa);
    testSmsRateConfig($fixtures, $qa);
    testSmsChallenges($qa, $db);
    testSmsRaces($qa);
    testSmsNamespaces($qa);
}
