<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

const PLATFORM_ADMINS = '/saas/platform/admins';

function testPlatformAdmins(Fixtures $fixtures, SmsLoginFixture $smsLogin): void
{
    $db = $fixtures->database('qa_platform');
    migrate($fixtures);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $db->prepare('INSERT INTO starter_accounts (phone, created_at, updated_at) VALUES (?, NOW(), NOW())')
        ->execute(['+86' . OTHER_PHONE]);
    $existingId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO starter_organizations (name, subscription_expires_at) VALUES ('QA platform', '2026-02-01')");
    $organizationId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO starter_invitations (organization_id, phone, expires_at, status) VALUES (?, ?, '2026-01-08', 'pending')")
        ->execute([$organizationId, '+86' . OTHER_PHONE]);
    // Membership storage can be installed independently of the platform administrator feature.
    $hasMemberships = $db->query("SHOW TABLES LIKE 'starter_memberships'")->fetchColumn() === 'starter_memberships';
    if ($hasMemberships) {
        $db->prepare("INSERT INTO starter_memberships (organization_id, account_id, role) VALUES (?, ?, 'member')")
            ->execute([$organizationId, $existingId]);
    }
    $organizationState = platformOrganizationState($db, $hasMemberships);

    $smsLogin->withHost('platform-admins', static function (int $port) use ($fixtures, $smsLogin, $db, $existingId): void {
        $smsLogin->clock(SMS_TIME);
        $initial = sessionTokens(smsLogin($port, $smsLogin->issue($port)));
        $actor = $initial['access_token'];
        $actorId = platformCurrentAccount($port, $actor);
        $organization = sessionTokens(smsLogin($port, $smsLogin->issue($port, OTHER_PHONE),
            phone: OTHER_PHONE, purpose: 'organization'));
        $messages = count($smsLogin->messages());
        $grantBody = ['phone' => SMS_MEMBER];
        smsExpect(smsRequest($port, 'POST', PLATFORM_ADMINS, $grantBody), 401, 'unauthenticated', 'Grant without a session');
        smsExpect(smsRequest($port, 'POST', PLATFORM_ADMINS, $grantBody, sessionBearer($organization['access_token'])),
            403, 'token_purpose_mismatch', 'Grant with an organization session');
        platformRevoke($port, $organization['access_token'], $actorId, 403, 'token_purpose_mismatch');
        smsExpect(smsRequest($port, 'POST', PLATFORM_ADMINS, ['phone' => '+12025550123'], sessionBearer($actor)),
            422, 'phone_invalid', 'Grant with an invalid phone');
        platformRevoke($port, $actor, $actorId, 409, 'last_platform_admin_required');
        sessionPlatform($port, $actor);
        sessionTokens(sessionRefresh($port, $initial['refresh_token']));

        $newId = platformGrant($port, $actor, SMS_MEMBER);
        check(platformGrant($port, $actor, '+86' . OTHER_PHONE) === $existingId, 'Platform grant replaced the existing account');
        smsExpect(smsRequest($port, 'POST', PLATFORM_ADMINS, ['phone' => '+86' . SMS_MEMBER], sessionBearer($actor)),
            409, 'platform_admin_already_granted', 'Repeated platform grant for the same normalized phone');
        check(count($smsLogin->messages()) === $messages, 'Platform grant sent an invitation SMS');
        $new = sessionTokens(smsLogin($port, $smsLogin->issue($port, SMS_MEMBER), phone: SMS_MEMBER));
        check(platformCurrentAccount($port, $new['access_token']) === $newId, 'New administrator logged in to the wrong account');

        $smsLogin->clock(SMS_TIME + 60);
        $old = sessionTokens(smsLogin($port, $smsLogin->issue($port, OTHER_PHONE), phone: OTHER_PHONE));
        $smsLogin->clock(SMS_TIME + 120);
        $second = sessionTokens(smsLogin($port, $smsLogin->issue($port, OTHER_PHONE), phone: OTHER_PHONE));
        platformRevoke($port, $actor, $newId);
        platformRevoke($port, $actor, $existingId);
        platformRevoke($port, $actor, $existingId, 404, 'not_found');
        foreach ([$old, $second] as $session) {
            sessionPlatform($port, $session['access_token'], 403, 'forbidden');
            sessionRefresh($port, $session['refresh_token'], 403, 'forbidden');
        }
        $organization = sessionTokens(sessionRefresh($port, $organization['refresh_token']));
        sessionPlatform($port, $organization['access_token'], 403, 'token_purpose_mismatch');
        check(platformGrant($port, $actor, OTHER_PHONE) === $existingId, 'Regrant replaced the revoked account');
        foreach ([$old, $second] as $session) {
            sessionPlatform($port, $session['access_token'], 401, 'token_invalid');
            sessionRefresh($port, $session['refresh_token'], 401, 'token_invalid');
        }

        $smsLogin->clock(SMS_TIME + 180);
        $reissued = sessionTokens(smsLogin($port, $smsLogin->issue($port, OTHER_PHONE), phone: OTHER_PHONE));
        $responses = platformGuardRace($fixtures, $db, [
            static fn () => smsConnect($port, 'POST', PLATFORM_ADMINS, ['phone' => SMS_UNKNOWN], sessionBearer($reissued['access_token'])),
        ], static function () use ($db, $existingId): void {
            // Apply the effects of revoke and regrant before the request can acquire the guard.
            $db->prepare("UPDATE starter_platform_admins SET status = 'revoked', session_epoch = session_epoch + 1 WHERE account_id = ?")
                ->execute([$existingId]);
            $db->prepare("UPDATE starter_platform_admins SET status = 'active' WHERE account_id = ?")->execute([$existingId]);
        });
        smsExpect($responses[0], 401, 'token_invalid', 'Previously authorized mutation resumed after revoke and regrant');
        check((int) $db->query('SELECT COUNT(*) FROM starter_accounts')->fetchColumn() === 3,
            'Stale platform request left behind a new account');
        echo "PASS platform grant, account reuse, purpose isolation and session invalidation after revoke and regrant\n";

        $smsLogin->clock(SMS_TIME + 240);
        $current = sessionTokens(smsLogin($port, $smsLogin->issue($port, OTHER_PHONE), phone: OTHER_PHONE));
        $responses = platformGuardRace($fixtures, $db, [
            static fn () => smsConnect($port, 'DELETE', PLATFORM_ADMINS . '/' . $actorId, headers: sessionBearer($actor)),
            static fn () => smsConnect($port, 'DELETE', PLATFORM_ADMINS . '/' . $existingId, headers: sessionBearer($current['access_token'])),
        ]);
        smsRaceResults($responses, ['200:success', '409:last_platform_admin_required']);
        $firstWon = $responses[0][0] === 200;
        $winner = $firstWon ? $initial : $current;
        $survivor = $firstWon ? $current : $initial;
        sessionPlatform($port, $winner['access_token'], 403, 'forbidden');
        sessionPlatform($port, $survivor['access_token']);
        $survivorId = $firstWon ? $existingId : $actorId;
        $winnerId = $firstWon ? $actorId : $existingId;
        check((int) $db->query("SELECT COUNT(*) FROM starter_platform_admins WHERE status = 'active'")->fetchColumn() === 1,
            'Concurrent self-revocation did not preserve exactly one active administrator');
        platformAssertAudit($db, $actorId, $newId, $existingId, $winnerId, $survivorId);
        echo "PASS two Webman workers serialize self-revocation and durably audit the last-admin denial\n";

        $winnerPhone = $firstWon ? PHONE : OTHER_PHONE;
        platformGrant($port, $survivor['access_token'], $winnerPhone);
        $smsLogin->clock(SMS_TIME + 300);
        $restored = sessionTokens(smsLogin($port, $smsLogin->issue($port, $winnerPhone), phone: $winnerPhone));
        $responses = platformGuardRace($fixtures, $db, [
            static fn () => smsConnect($port, 'DELETE', PLATFORM_ADMINS . '/' . $winnerId,
                headers: sessionBearer($survivor['access_token'])),
            static fn () => smsConnect($port, 'DELETE', PLATFORM_ADMINS . '/' . $survivorId,
                headers: sessionBearer($restored['access_token'])),
        ]);
        smsRaceResults($responses, ['200:success', '403:forbidden']);
        $remaining = $responses[0][0] === 200 ? $survivor : $restored;
        $revoked = $responses[0][0] === 200 ? $restored : $survivor;
        sessionPlatform($port, $remaining['access_token']);
        sessionPlatform($port, $revoked['access_token'], 403, 'forbidden');
        echo "PASS concurrent mutual revocation rejects the already-authenticated request from the revoked administrator\n";
    });

    check(platformOrganizationState($db, $hasMemberships) === $organizationState,
        'Platform administration changed organization, membership, or invitation state');
}

function platformCurrentAccount(int $port, string $token): int
{
    $me = smsExpect(smsRequest($port, 'GET', PLATFORM_ME, headers: sessionBearer($token)), 200, 'success', 'Platform access');

    return $me['data']['account_id'];
}

function platformGrant(int $port, string $token, string $phone): int
{
    $body = smsExpect(smsRequest($port, 'POST', PLATFORM_ADMINS, ['phone' => $phone], sessionBearer($token)),
        200, 'success', 'Grant platform administrator');
    $id = $body['data']['account_id'] ?? null;
    check(is_int($id) && $id > 0, 'Grant did not return the target account ID');

    return $id;
}

function platformRevoke(int $port, string $token, int $id, int $status = 200, string $code = 'success'): void
{
    $body = smsExpect(smsRequest($port, 'DELETE', PLATFORM_ADMINS . '/' . $id, headers: sessionBearer($token)),
        $status, $code, 'Revoke platform administrator');
    check($body['data'] === null, 'Revoke exposed account data');
}

/** @return list<array<string, mixed>[]> */
function platformOrganizationState(PDO $db, bool $hasMemberships): array
{
    return [
        $db->query('SELECT * FROM starter_organizations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $db->query('SELECT * FROM starter_invitations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $hasMemberships ? $db->query('SELECT * FROM starter_memberships ORDER BY organization_id, account_id')->fetchAll(PDO::FETCH_ASSOC) : [],
    ];
}

/**
 * @param list<callable():resource> $requests
 * @return list<array{int,array<string,mixed>}>
 */
function platformGuardRace(Fixtures $fixtures, PDO $db, array $requests, ?callable $whileBlocked = null): array
{
    $clients = [];
    $db->beginTransaction();
    try {
        $db->query('SELECT id FROM starter_platform_guard WHERE id = 1 FOR UPDATE')->closeCursor();
        clearstatcache(true, $fixtures->sqlLog);
        $offset = filesize($fixtures->sqlLog);
        check($offset !== false, 'Could not find the guarded requests in the MySQL query log');
        foreach ($requests as $request) {
            $clients[] = $request();
            // Wait for each worker to block on the guard before letting the next one accept a request.
            $deadline = microtime(true) + 3;
            do {
                $log = file_get_contents($fixtures->sqlLog, false, null, $offset);
                check($log !== false, 'Could not read guard lock attempts from the MySQL query log');
                preg_match_all('/\b(\d+)\s+(?:Query|Execute)\s+select [^\r\n]*\x60starter_platform_guard\x60[^\r\n]*for update/i', $log, $matches);
                if (count(array_unique($matches[1])) === count($clients)) {
                    break;
                }
                check(microtime(true) < $deadline, 'Expected Webman workers did not reach the held platform guard');
                usleep(1_000);
            } while (true);
        }
        if ($whileBlocked !== null) {
            $whileBlocked();
        }
        $db->commit();

        return array_map(smsReadResponse(...), $clients);
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach ($clients as $client) {
            fclose($client);
        }
    }
}

function platformAssertAudit(PDO $db, int $actorId, int $newId, int $existingId, int $winnerId, int $survivorId): void
{
    $rows = $db->query("SELECT action, actor_account_id, target_account_id, metadata FROM starter_audit_logs
        WHERE action IN ('platform_admin_granted', 'platform_admin_revoked', 'platform_admin_revoke_denied') ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    $expected = [
        ['platform_admin_revoke_denied', $actorId, $actorId],
        ['platform_admin_granted', $actorId, $newId],
        ['platform_admin_granted', $actorId, $existingId],
        ['platform_admin_revoked', $actorId, $newId],
        ['platform_admin_revoked', $actorId, $existingId],
        ['platform_admin_granted', $actorId, $existingId],
        ['platform_admin_revoked', $winnerId, $winnerId],
        ['platform_admin_revoke_denied', $survivorId, $survivorId],
    ];
    check(array_map(static fn (array $row): array => [
        $row['action'], (int) $row['actor_account_id'], (int) $row['target_account_id'],
    ], $rows) === $expected, 'Platform audit actions or account IDs differ from the committed results');
    foreach ($rows as $index => $row) {
        $metadata = json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR);
        $expectedMetadata = match ($row['action']) {
            'platform_admin_granted' => [
                'account_created' => $index === 1,
                'phone_masked' => $index === 1 ? '**********8001' : '**********9000',
                'source' => 'api',
                'result' => 'success',
            ],
            'platform_admin_revoked' => ['source' => 'api', 'result' => 'success'],
            'platform_admin_revoke_denied' => ['source' => 'api', 'result' => 'denied', 'reason' => 'last_platform_admin_required'],
        };
        ksort($metadata);
        ksort($expectedMetadata);
        check($metadata === $expectedMetadata, 'Platform audit metadata differs from the redacted contract');
    }
}
