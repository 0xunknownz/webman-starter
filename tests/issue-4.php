<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

const SESSION_REFRESH = '/saas/auth/refresh';
const SESSION_LOGOUT = '/saas/auth/logout';

/** @param array<string,mixed> $credentials @return array{access_token:string,refresh_token:string} */
function sessionTokens(array $credentials, int $expiresIn = 900): array
{
    check(($credentials['token_type'] ?? null) === 'Bearer' && ($credentials['expires_in'] ?? null) === $expiresIn,
        'Session response did not advertise the expected Bearer lifetime');
    $access = smsToken($credentials);
    $refresh = $credentials['refresh_token'] ?? null;
    check(is_string($refresh) && $refresh !== '' && $refresh !== $access,
        'Session response did not issue a distinct refresh token');

    return ['access_token' => $access, 'refresh_token' => $refresh];
}

/** @return list<string> */
function sessionBearer(string $accessToken): array
{
    return ["Authorization: Bearer $accessToken"];
}

/** @return array<string,mixed> */
function sessionRefresh(int $port, string $refreshToken, int $status = 200, string $code = 'success'): array
{
    return smsExpect(smsRequest($port, 'POST', SESSION_REFRESH, ['refresh_token' => $refreshToken]),
        $status, $code, 'Refresh session')['data'] ?? [];
}

function sessionPlatform(int $port, string $accessToken, int $status = 200, string $code = 'success'): void
{
    smsExpect(smsRequest($port, 'GET', PLATFORM_ME, headers: sessionBearer($accessToken)), $status, $code, 'Platform access');
}

function sessionLogout(int $port, string $accessToken, int $status = 200, string $code = 'success'): void
{
    $response = smsExpect(smsRequest($port, 'POST', SESSION_LOGOUT, headers: sessionBearer($accessToken)),
        $status, $code, 'Logout');
    check($response['data'] === null, 'Logout did not return null session data');
}

function testSessionDevices(SmsLoginFixture $smsLogin): void
{
    $smsLogin->withHost('session-devices', static function (int $port) use ($smsLogin): void {
        $smsLogin->clock(SMS_TIME);
        $first = sessionTokens(smsLogin($port, $smsLogin->issue($port)));
        $smsLogin->clock(SMS_TIME + 60);
        $second = sessionTokens(smsLogin($port, $smsLogin->issue($port)));
        check($first['refresh_token'] !== $second['refresh_token'], 'Separate devices shared a refresh credential');

        sessionRefresh($port, 'invalid', 401, 'token_invalid');
        sessionLogout($port, $first['refresh_token'], 401, 'token_invalid');
        smsExpect(smsRequest($port, 'POST', SESSION_REFRESH), 422, 'validation_failed', 'Refresh without a token');
        smsExpect(smsRequest($port, 'POST', SESSION_LOGOUT), 401, 'unauthenticated', 'Logout without Bearer');

        $rotatedFirst = sessionTokens(sessionRefresh($port, $first['refresh_token']));
        $rotatedSecond = sessionTokens(sessionRefresh($port, $second['refresh_token']));
        check($rotatedFirst['refresh_token'] !== $first['refresh_token']
            && $rotatedSecond['refresh_token'] !== $second['refresh_token'], 'Refresh credentials did not rotate');
        sessionRefresh($port, $first['refresh_token'], 401, 'token_invalid');
        sessionRefresh($port, $second['refresh_token'], 401, 'token_invalid');

        sessionLogout($port, $rotatedFirst['access_token']);
        sessionPlatform($port, $first['access_token'], 401, 'token_invalid');
        sessionPlatform($port, $rotatedFirst['access_token'], 401, 'token_invalid');
        sessionRefresh($port, $rotatedFirst['refresh_token'], 401, 'token_invalid');
        sessionLogout($port, $rotatedFirst['access_token'], 401, 'token_invalid');
        sessionPlatform($port, $rotatedSecond['access_token']);
        $survivor = sessionTokens(sessionRefresh($port, $rotatedSecond['refresh_token']));
        sessionLogout($port, $survivor['access_token']);
        sessionRefresh($port, $survivor['refresh_token'], 401, 'token_invalid');
    });
    echo "PASS two independently rotated devices, replay rejection and immediate session-only logout\n";
}

function testSessionDeadlines(SmsLoginFixture $smsLogin): void
{
    $smsLogin->withHost('session-deadlines', static function (int $port) use ($smsLogin): void {
        $smsLogin->clock(SMS_TIME);
        $initial = sessionTokens(smsLogin($port, $smsLogin->issue($port)));
        $smsLogin->clock(SMS_TIME + 899);
        sessionPlatform($port, $initial['access_token']);
        $smsLogin->clock(SMS_TIME + 900);
        sessionPlatform($port, $initial['access_token'], 401, 'token_expired');
        sessionLogout($port, $initial['access_token'], 401, 'token_expired');
        $early = sessionTokens(sessionRefresh($port, $initial['refresh_token']));
        sessionPlatform($port, $early['access_token']);

        $smsLogin->clock(SMS_TIME + 604799);
        $last = sessionTokens(sessionRefresh($port, $early['refresh_token']), 1);
        sessionPlatform($port, $last['access_token']);
        $smsLogin->clock(SMS_TIME + 604800);
        sessionPlatform($port, $last['access_token'], 401, 'token_expired');
        sessionRefresh($port, $last['refresh_token'], 401, 'token_invalid');
        $smsLogin->clock(SMS_TIME + 604801);
        sessionRefresh($port, $last['refresh_token'], 401, 'token_invalid');
    });
    echo "PASS exact 15-minute access expiry and 7-day absolute refresh deadline across rotations\n";
}

function testSessionConcurrentRefresh(SmsLoginFixture $smsLogin): void
{
    $smsLogin->withHost('session-race', static function (int $port) use ($smsLogin): void {
        $smsLogin->clock(SMS_TIME);
        $initial = sessionTokens(smsLogin($port, $smsLogin->issue($port)));
        $request = ['refresh_token' => $initial['refresh_token']];
        $responses = $smsLogin->race($port, SMS_TIME + 1, SESSION_REFRESH, [$request, $request]);
        smsRaceResults($responses, ['200:success', '401:token_invalid']);
        foreach ($responses as [$status, $body]) {
            if ($status === 200) {
                $winner = sessionTokens($body['data']);
                sessionPlatform($port, $winner['access_token']);
                sessionTokens(sessionRefresh($port, $winner['refresh_token']));
            }
        }
        sessionRefresh($port, $initial['refresh_token'], 401, 'token_invalid');
    });
    echo "PASS simultaneous refresh on two Webman workers has one winner\n";
}

function testSessionRefreshAfterConcurrentLogout(Fixtures $fixtures, SmsLoginFixture $smsLogin, PDO $db): void
{
    $smsLogin->withHost('session-logout-race', static function (int $port) use ($fixtures, $smsLogin, $db): void {
        $smsLogin->clock(SMS_TIME);
        $initial = sessionTokens(smsLogin($port, $smsLogin->issue($port)));
        $smsLogin->clock(SMS_TIME + 1);
        $client = null;
        $db->beginTransaction();
        try {
            $accountLock = $db->prepare('SELECT id FROM starter_accounts WHERE phone = ? FOR UPDATE');
            $accountLock->execute(['+86' . PHONE]);
            $accountLock->closeCursor();
            clearstatcache(true, $fixtures->sqlLog);
            $offset = filesize($fixtures->sqlLog);
            check($offset !== false, 'Could not locate the refresh race in the MySQL query log');
            $client = smsConnect($port, 'POST', SESSION_REFRESH, ['refresh_token' => $initial['refresh_token']]);

            // The refresh has read live Redis state when the worker requests the account lock held here.
            $deadline = microtime(true) + 2;
            do {
                $log = file_get_contents($fixtures->sqlLog, false, null, $offset);
                check($log !== false, 'Could not read the refresh race in the MySQL query log');
                if (preg_match('/from \x60starter_accounts\x60[^\r\n]*for update/i', $log) === 1) {
                    break;
                }
                check(microtime(true) < $deadline, 'Refresh did not reach the held account lock');
                usleep(1_000);
            } while (true);

            sessionLogout($port, $initial['access_token']);
            $db->rollBack();
            smsExpect(smsReadResponse($client), 401, 'token_invalid', 'Refresh suspended until after logout');
            sessionPlatform($port, $initial['access_token'], 401, 'token_invalid');
            sessionRefresh($port, $initial['refresh_token'], 401, 'token_invalid');
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (is_resource($client)) {
                fclose($client);
            }
        }
    });
    echo "PASS refresh with an already-read session cannot revive a concurrent logout\n";
}

function testSessionEpochs(SmsLoginFixture $smsLogin, PDO $db): void
{
    $smsLogin->withHost('session-epochs', static function (int $port) use ($smsLogin, $db): void {
        $smsLogin->clock(SMS_TIME);
        $platform = sessionTokens(smsLogin($port, $smsLogin->issue($port)));
        $smsLogin->clock(SMS_TIME + 60);
        $organization = sessionTokens(smsLogin($port, $smsLogin->issue($port), purpose: 'organization'));
        sessionPlatform($port, $organization['access_token'], 403, 'token_purpose_mismatch');

        // #4 uses the real state the subsequent administrator endpoints will write.
        $platformState = $db->prepare('UPDATE starter_platform_admins SET status = ?, session_epoch = session_epoch + 1
            WHERE account_id = (SELECT id FROM starter_accounts WHERE phone = ?)');
        $platformState->execute(['revoked', '+86' . PHONE]);
        sessionPlatform($port, $platform['access_token'], 403, 'forbidden');
        sessionRefresh($port, $platform['refresh_token'], 403, 'forbidden');
        sessionLogout($port, $platform['access_token'], 403, 'forbidden');
        $organization = sessionTokens(sessionRefresh($port, $organization['refresh_token']));
        sessionPlatform($port, $organization['access_token'], 403, 'token_purpose_mismatch');

        $platformState->execute(['active', '+86' . PHONE]);
        sessionPlatform($port, $platform['access_token'], 401, 'token_invalid');
        sessionRefresh($port, $platform['refresh_token'], 401, 'token_invalid');
        $smsLogin->clock(SMS_TIME + 120);
        $reissued = sessionTokens(smsLogin($port, $smsLogin->issue($port)));
        sessionPlatform($port, $reissued['access_token']);

        $accountEpoch = $db->prepare('UPDATE starter_accounts SET session_epoch = session_epoch + 1 WHERE phone = ?');
        $accountEpoch->execute(['+86' . PHONE]);
        sessionPlatform($port, $reissued['access_token'], 401, 'token_invalid');
        sessionRefresh($port, $reissued['refresh_token'], 401, 'token_invalid');
        sessionRefresh($port, $organization['refresh_token'], 401, 'token_invalid');
        sessionLogout($port, $organization['access_token'], 401, 'token_invalid');

        $smsLogin->clock(SMS_TIME + 180);
        $orgLogout = sessionTokens(smsLogin($port, $smsLogin->issue($port), purpose: 'organization'));
        sessionLogout($port, $orgLogout['access_token']);
        sessionRefresh($port, $orgLogout['refresh_token'], 401, 'token_invalid');
    });
    echo "PASS refreshed purpose isolation, platform revocation and regrant, account epoch invalidation and organization logout\n";
}

function testSessions(Fixtures $fixtures, SmsLoginFixture $smsLogin): void
{
    $db = $fixtures->database('qa_sessions');
    migrate($fixtures);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $db->prepare('INSERT INTO starter_accounts (phone, created_at, updated_at) VALUES (?, NOW(), NOW())')
        ->execute(['+86' . SMS_MEMBER]);
    $db->prepare("INSERT INTO starter_platform_admins (account_id, status, created_at, updated_at)
        SELECT id, 'active', NOW(), NOW() FROM starter_accounts WHERE phone = ?")->execute(['+86' . SMS_MEMBER]);
    testSessionDevices($smsLogin);
    testSessionDeadlines($smsLogin);
    testSessionConcurrentRefresh($smsLogin);
    testSessionRefreshAfterConcurrentLogout($fixtures, $smsLogin, $db);
    testSessionEpochs($smsLogin, $db);
}
