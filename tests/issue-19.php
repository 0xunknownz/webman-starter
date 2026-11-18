<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

require __DIR__ . '/d2-fixtures.php';

const OPENING_ROUTE = '/saas/platform/organizations';
const OLD_OWNER_PHONE = '13900139001';
const NEW_OWNER_PHONE = '13900139002';

function issue19Expect(array $response, int $status, string $code, string $label): mixed
{
    $body = smsExpect($response, $status, $code, $label);
    check(is_string($body['trace_id'] ?? null) && $body['trace_id'] !== '', "$label has a trace ID");

    return $body['data'];
}

function issue19LinkToken(array $invite): string
{
    return substr((string) parse_url($invite['link'], PHP_URL_FRAGMENT), strlen('token='));
}

function issue19Proof(SmsLoginFixture $qa, int $port, array $invite, string $phone): string
{
    $input = ['token' => issue19LinkToken($invite), 'phone' => $phone];
    $sent = issue19Expect(smsRequest($port, 'POST', '/saas/invitations/sms/send', $input), 200, 'success', 'Send owner proof SMS');
    $messages = $qa->messages();
    $sms = $messages[array_key_last($messages)];
    check($sms['phone'] === '+86' . $phone, 'Only the newly selected owner can receive this challenge');
    $verified = issue19Expect(smsRequest($port, 'POST', '/saas/invitations/sms/verify', $input + [
        'challenge_id' => $sent['challenge_id'], 'code' => $sms['code'],
    ]), 200, 'success', 'Verify owner proof');

    return $verified['credential'];
}

function issue19Accept(int $port, array $invite, string $phone, string $credential): array
{
    return smsRequest($port, 'POST', '/saas/invitations/accept', [
        'token' => issue19LinkToken($invite), 'phone' => $phone, 'credential' => $credential,
    ]);
}

/** Wait for a real worker to reach its locking read while the test transaction holds the row. */
function issue19AwaitLock(PDO $sql, string $table): void
{
    $wait = $sql->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST '
        . 'WHERE ID <> CONNECTION_ID() AND DB = DATABASE() AND INFO LIKE ?');
    $deadline = microtime(true) + 3;
    do {
        $wait->execute(["%from %$table%for update%"]);
        if ((int) $wait->fetchColumn() > 0) {
            return;
        }
        check(microtime(true) < $deadline, "Webman worker must reach the $table locking read");
        usleep(1000);
    } while (true);
}

function checkIssue19Open(PDO $sql, callable $request, int $package, string $page, array $organizationAuth): array
{
    $payload = [
        'name' => '　第一家组织　', 'phone' => OLD_OWNER_PHONE, 'package_id' => $package,
        'starts_at' => '2026-01-01T19:00:00+08:00', 'expires_at' => '2026-01-01T21:00:00+08:00',
    ];
    $initialRows = (int) $sql->query('SELECT COUNT(*) FROM starter_organizations')->fetchColumn();
    $initialAudit = (int) $sql->query('SELECT COUNT(*) FROM starter_audit_logs WHERE organization_id IS NOT NULL')->fetchColumn();
    issue19Expect($request('POST', OPENING_ROUTE, $payload, []), 401, 'unauthenticated', 'Opening requires a session');
    issue19Expect($request('POST', OPENING_ROUTE, $payload, $organizationAuth), 403, 'token_purpose_mismatch', 'Organization admin cannot open');
    foreach ([null, [], '　 ', "name\n", "na\x00me", str_repeat('组', 101)] as $badName) {
        issue19Expect($request('POST', OPENING_ROUTE, ['name' => $badName] + $payload), 422, 'organization_name_invalid', 'Reject invalid organization name');
    }
    issue19Expect($request('POST', OPENING_ROUTE, ['expires_at' => $payload['starts_at']] + $payload), 422,
        'subscription_window_invalid', 'Reject equal subscription endpoints');
    issue19Expect($request('POST', OPENING_ROUTE, ['starts_at' => '2026-01-01T11:00:00'] + $payload), 422,
        'subscription_window_invalid', 'Reject missing timezone');
    issue19Expect($request('POST', OPENING_ROUTE, ['phone' => '123'] + $payload), 422, 'phone_invalid', 'Reject non-mobile owner');
    $sql->exec("UPDATE starter_packages SET retired_at = UTC_TIMESTAMP() WHERE id = $package");
    issue19Expect($request('POST', OPENING_ROUTE, $payload), 409, 'package_retired', 'Retired templates cannot open organizations');
    $sql->exec("UPDATE starter_packages SET retired_at = NULL, seat_limit = 0 WHERE id = $package");
    issue19Expect($request('POST', OPENING_ROUTE, $payload), 422, 'package_invalid', 'Reject template with zero seats');
    $sql->exec("UPDATE starter_packages SET seat_limit = 1 WHERE id = $package");
    issue19Expect($request('POST', OPENING_ROUTE, ['expires_at' => gmdate(DATE_ATOM, SMS_TIME)] + $payload), 403,
        'subscription_expired', 'Do not issue a first-owner invite at subscription expiry');
    check((int) $sql->query('SELECT COUNT(*) FROM starter_organizations')->fetchColumn() === $initialRows
        && (int) $sql->query('SELECT COUNT(*) FROM starter_audit_logs WHERE organization_id IS NOT NULL')->fetchColumn() === $initialAudit
        && (int) $sql->query('SELECT COUNT(*) FROM starter_invitations')->fetchColumn() === 0,
        'Rejected openings write no organization, invitation or organization audit');

    $opened = issue19Expect($request('POST', OPENING_ROUTE, $payload), 201, 'success', 'Platform opens a pending organization');
    check($opened['name'] === '第一家组织' && $opened['phase'] === 'pending' && !$opened['suspended'] && !$opened['archived'],
        'Opening trims the name and returns pending state');
    check(str_starts_with($opened['link'], $page . '#token=') && $opened['expires_at'] === gmdate(DATE_ATOM, SMS_TIME + 61 + 604800),
        'Opening returns the configured signed owner link expiring in seven days');
    $organization = $opened['organization_id'];
    $sql->exec("UPDATE starter_packages SET name = 'Edited Template', seat_limit = 3 WHERE id = $package");
    $row = $sql->query("SELECT * FROM starter_organizations WHERE id = $organization")->fetch(PDO::FETCH_ASSOC);
    check($row['name'] === '第一家组织' && $row['package_name'] === 'One Seat' && (int) $row['seat_limit'] === 1
        && $row['subscription_starts_at'] === '2026-01-01 11:00:00' && $row['subscription_expires_at'] === '2026-01-01 13:00:00',
        'Opening stores UTC dates and an independent one-seat package snapshot');
    $sql->exec("UPDATE starter_packages SET name = 'One Seat', seat_limit = 1 WHERE id = $package");
    $reservation = $sql->query("SELECT status, role, reserved FROM starter_invitations WHERE organization_id = $organization")->fetch(PDO::FETCH_ASSOC);
    check($reservation['status'] === 'pending' && $reservation['role'] === 'owner' && (int) $reservation['reserved'] === 1,
        'Opening atomically reserves one owner seat');
    $upcoming = issue19Expect($request('POST', OPENING_ROUTE, [
        'name' => str_repeat('组', 100), 'starts_at' => '2026-01-01T20:30:00+08:00',
    ] + $payload), 201, 'success', 'Upcoming subscriptions can open and accept a 100-character name');

    return [$opened, $upcoming];
}

function checkIssue19Lifecycle(
    SmsLoginFixture $qa,
    PDO $sql,
    int $port,
    callable $request,
    array $opened,
    int $originalAccount,
    int $replacementAccount,
    int $actor,
    array $organizationAuth,
): void {
    $id = $opened['organization_id'];
    $path = OPENING_ROUTE . "/$id";
    $invitePath = static fn (array $invite): string => $path . '/owner-invitations/' . $invite['invitation_id'];
    $linkData = static fn (array $invite): array => array_intersect_key($invite, array_flip(['invitation_id', 'link', 'expires_at']));
    $organization = static fn (): array => $sql->query("SELECT * FROM starter_organizations WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    $invitations = static fn (): array => $sql->query("SELECT * FROM starter_invitations WHERE organization_id = $id ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $reservations = static fn (): int => (int) $sql->query("SELECT COUNT(*) FROM starter_invitations WHERE organization_id = $id AND reserved = 1")->fetchColumn();
    $memberships = static fn (): array => $sql->query("SELECT account_id, role FROM starter_memberships WHERE organization_id = $id")->fetchAll(PDO::FETCH_ASSOC);
    $subscription = static fn (): array => array_intersect_key($organization(), array_flip(['subscription_starts_at', 'subscription_expires_at', 'package_id', 'package_name', 'seat_limit']));
    $originalSubscription = $subscription();
    $pathOriginal = $invitePath($opened);
    $change = ['phone' => NEW_OWNER_PHONE, 'reason' => 'recipient_changed'];

    issue19Expect($request('POST', "$path/cancel-opening", headers: $organizationAuth), 403, 'token_purpose_mismatch', 'Organization admin cannot cancel');
    issue19Expect($request('POST', "$path/restore", headers: $organizationAuth), 403, 'token_purpose_mismatch', 'Organization admin cannot restore');
    issue19Expect($request('POST', "$pathOriginal/change-phone", $change, $organizationAuth), 403, 'token_purpose_mismatch', 'Organization admin cannot change the owner phone');
    $kept = issue19Expect($request('GET', "$pathOriginal/link"), 200, 'success', 'Retrieve original link with the only seat occupied');
    check($kept === $linkData($opened) && $reservations() === 1, 'Retrieval preserves link, reservation, and expiration');
    issue19Expect($request('POST', "$pathOriginal/change-phone", ['phone' => '+86' . OLD_OWNER_PHONE] + $change), 422,
        'validation_failed', 'Changing the recipient requires a different normalized number');
    issue19Expect($request('POST', "$pathOriginal/change-phone", ['reason' => 'Call +' . OLD_OWNER_PHONE] + $change), 422,
        'validation_failed', 'Only controlled reasons enter the audit');

    $oldCredential = issue19Proof($qa, $port, $opened, OLD_OWNER_PHONE);
    $smsBefore = $qa->messages();
    $emailBefore = emailMessages($qa);
    $before = $invitations();
    $sql->exec("CREATE TRIGGER issue19_fail_audit BEFORE INSERT ON starter_audit_logs FOR EACH ROW "
        . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test audit rejection'");
    try {
        issue19Expect($request('POST', "$pathOriginal/change-phone", $change), 503, 'audit_unavailable', 'Audit failure rejects invite replacement');
        issue19Expect($request('POST', "$path/cancel-opening"), 503, 'audit_unavailable', 'Audit failure rejects cancellation');
    } finally {
        $sql->exec('DROP TRIGGER issue19_fail_audit');
    }
    check($invitations() === $before && $organization()['archived_at'] === null, 'Failed audits roll back invitations and organization state');
    $changed = issue19Expect($request('POST', "$pathOriginal/change-phone", $change), 201, 'success', 'Change the owner recipient while the one-seat package is full');
    $changedPath = $invitePath($changed);
    check($changed['link'] !== $opened['link'] && $reservations() === 1 && $memberships() === [] && $subscription() === $originalSubscription,
        'Replacement has a new link and preserves one reservation and the original subscription');
    check($sql->query("SELECT phone FROM starter_accounts WHERE id = $originalAccount")->fetchColumn() === '+86' . OLD_OWNER_PHONE,
        'Changing the invite recipient leaves the original account phone untouched');
    issue19Expect(issue19Accept($port, $opened, OLD_OWNER_PHONE, $oldCredential), 409, 'invitation_invalid', 'Old proof immediately fails after recipient change');
    issue19Expect(issue19Accept($port, $changed, NEW_OWNER_PHONE, $oldCredential), 401, 'purpose_mismatch', 'Old proof cannot authorize the new invitation');
    issue19Expect($request('POST', "$pathOriginal/reissue"), 409, 'invitation_invalid', 'Stale selected invitation cannot replace the current recipient');
    check($qa->messages() === $smsBefore && emailMessages($qa) === $emailBefore, 'Platform changes send neither SMS nor email');

    $qa->clock(SMS_TIME + 62);
    $cancelCredential = issue19Proof($qa, $port, $changed, NEW_OWNER_PHONE);
    $suspended = issue19Expect($request('POST', "$path/suspend"), 200, 'success', 'Suspend pending opening');
    check($suspended['suspended'] && $suspended['phase'] === 'pending' && !$suspended['archived'], 'Suspension preserves other state flags');
    foreach ([['GET', '/link', null], ['POST', '/reissue', null], ['POST', '/change-phone', $change]] as [$method, $suffix, $body]) {
        issue19Expect($request($method, "$changedPath$suffix", $body), 403, 'organization_suspended', 'Suspension wins over valid subscription');
    }
    issue19Expect($request('DELETE', $changedPath), 200, 'success', 'Platform can revoke an invitation while suspended');
    check($reservations() === 0, 'Revocation releases the seat');
    issue19Expect($request('POST', "$path/unsuspend"), 200, 'success', 'Platform unsuspends a pending organization');
    issue19Expect($request('GET', "$changedPath/link"), 409, 'invitation_invalid', 'Unsuspension does not revive a revoked invitation');
    check($subscription() === $originalSubscription, 'Unsuspension preserves subscription dates and snapshot');

    $qa->clock(SMS_TIME + 124);
    $reissued = issue19Expect($request('POST', "$changedPath/reissue"), 201, 'success', 'Reissue a revoked invitation for the same phone');
    check($reissued['expires_at'] === gmdate(DATE_ATOM, SMS_TIME + 124 + 604800)
        && $invitations()[2]['phone'] === '+86' . NEW_OWNER_PHONE, 'Reissue preserves target and restarts the seven-day lifetime');
    issue19Expect(issue19Accept($port, $changed, NEW_OWNER_PHONE, $cancelCredential), 409, 'invitation_invalid', 'Revocation invalidates the old link and proof');
    $cancelCredential = issue19Proof($qa, $port, $reissued, NEW_OWNER_PHONE);
    $reissuedPath = $invitePath($reissued);
    $expiry = gmdate('Y-m-d H:i:s', SMS_TIME + 124);
    $sql->exec("UPDATE starter_organizations SET subscription_expires_at = '$expiry' WHERE id = $id");
    $expiredSubscription = $subscription();
    foreach ([['GET', '/link', null], ['POST', '/reissue', null], ['POST', '/change-phone', $change]] as [$method, $suffix, $body]) {
        issue19Expect($request($method, "$reissuedPath$suffix", $body), 403, 'subscription_expired', 'Expired subscription blocks owner link management');
    }
    issue19Expect($request('POST', "$path/suspend"), 200, 'success', 'Suspend expired pending opening');
    $suspendedAt = $organization()['suspended_at'];
    $cancelled = issue19Expect($request('POST', "$path/cancel-opening"), 200, 'success', 'Cancel suspended and expired opening with no free seat');
    check($cancelled['archived'] && $cancelled['suspended'] && $cancelled['phase'] === 'pending' && $reservations() === 0
        && $memberships() === [] && $subscription() === $expiredSubscription, 'Cancellation archives pending state and releases its reservations');
    foreach ([['GET', '/link', null], ['POST', '/reissue', null], ['POST', '/change-phone', $change]] as [$method, $suffix, $body]) {
        issue19Expect($request($method, "$reissuedPath$suffix", $body), 403, 'organization_archived', 'Archive wins over suspended and expired');
    }
    issue19Expect(issue19Accept($port, $reissued, NEW_OWNER_PHONE, $cancelCredential), 409, 'invitation_invalid', 'Cancellation invalidates the live proof');
    $renamed = issue19Expect($request('PATCH', "$path/name", ['name' => '　恢复组织　']), 200, 'success', 'Platform can rename an archived organization');
    check($renamed['name'] === '恢复组织' && $renamed['archived'], 'Archival does not prevent name changes');
    $restored = issue19Expect($request('POST', "$path/restore"), 200, 'success', 'Restore while expired');
    check(!$restored['archived'] && $restored['suspended'] && $restored['phase'] === 'pending' && $organization()['suspended_at'] === $suspendedAt
        && $memberships() === [] && $reservations() === 0 && $subscription() === $expiredSubscription,
        'Restore clears only the archive marker');
    issue19Expect($request('POST', "$reissuedPath/reissue"), 403, 'organization_suspended', 'Restore keeps suspension effective');
    issue19Expect($request('POST', "$path/unsuspend"), 200, 'success', 'Unsuspend after restoring');
    issue19Expect($request('POST', "$reissuedPath/reissue"), 403, 'subscription_expired', 'Restore and unsuspend do not renew subscription');

    // Test fixture renews the subscription because the date adjustment endpoint belongs to D3.
    $sql->prepare('UPDATE starter_organizations SET subscription_expires_at = ? WHERE id = ?')->execute([$originalSubscription['subscription_expires_at'], $id]);
    issue19Expect($request('GET', "$reissuedPath/link"), 409, 'invitation_invalid', 'Renewal does not reanimate the prior link');
    $sql->exec("INSERT INTO starter_invitations (organization_id, phone, status, expires_at, role, reserved) "
        . "VALUES ($id, '+8613900139003', 'pending', '2026-01-02 00:00:00', 'member', 1)");
    $competing = (int) $sql->lastInsertId();
    $before = $invitations();
    issue19Expect($request('POST', "$reissuedPath/reissue"), 409, 'seat_limit_exceeded', 'A released reservation requires a free seat to reissue');
    issue19Expect($request('POST', "$reissuedPath/change-phone", ['phone' => OLD_OWNER_PHONE] + $change), 409,
        'seat_limit_exceeded', 'Changing a released reservation also needs a seat');
    check($invitations() === $before, 'Rejected replacement preserves invitation history and reservations');
    issue19Expect($request('POST', "$path/cancel-opening"), 200, 'success', 'Cancellation releases even a separate outstanding member invitation');
    check($reservations() === 0 && $sql->query("SELECT status FROM starter_invitations WHERE id = $competing")->fetchColumn() === 'revoked',
        'Cancellation invalidates all pending invitation roles');
    issue19Expect($request('POST', "$path/restore"), 200, 'success', 'Restore again with no reservations');
    $qa->clock(SMS_TIME + 186);
    $fresh = issue19Expect($request('POST', "$reissuedPath/reissue"), 201, 'success', 'Restored organization gets a separate fresh invitation');
    issue19Expect(issue19Accept($port, $reissued, NEW_OWNER_PHONE, $cancelCredential), 409, 'invitation_invalid', 'Old proof remains invalid after restoration and reissue');
    $credential = issue19Proof($qa, $port, $fresh, NEW_OWNER_PHONE);
    $accepted = issue19Expect(issue19Accept($port, $fresh, NEW_OWNER_PHONE, $credential), 200, 'success', 'Only the fresh link activates the restored organization');
    check($accepted['account_id'] === $replacementAccount && $accepted['role'] === 'owner'
        && $organization()['activated_at'] !== null && count($memberships()) === 1 && $reservations() === 0,
        'Activation reuses the replacement account, establishes its ownership and consumes the reservation');
    issue19Expect($request('POST', "$path/cancel-opening"), 403, 'forbidden', 'Activated organization cannot be cancelled as a pending opening');
    $ownerAuth = sessionBearer(smsToken(smsLogin($port, $qa->issue($port, NEW_OWNER_PHONE), phone: NEW_OWNER_PHONE, purpose: 'organization')));
    issue19Expect($request('POST', "$path/suspend"), 200, 'success', 'Platform can suspend an activated organization');
    issue19Expect($request('GET', "/saas/organizations/$id/members", headers: $ownerAuth), 403, 'organization_suspended', 'Suspension freezes organization-side member governance');
    issue19Expect($request('POST', "$path/unsuspend"), 200, 'success', 'Platform can unsuspend an activated organization');
    issue19Expect($request('GET', "/saas/organizations/$id/members", headers: $ownerAuth), 200, 'success', 'Member governance returns when unsuspended and entitled');
    check($subscription() === $originalSubscription && count($memberships()) === 1, 'Lifecycle operations preserve subscription and ownership');

    $audits = $sql->query("SELECT action, actor_account_id, metadata FROM starter_audit_logs WHERE organization_id = $id ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $actions = array_column($audits, 'action');
    foreach (['organization_created', 'organization_renamed', 'invitation_created', 'invitation_link_retrieved', 'invitation_reissued',
        'organization_owner_invitee_changed', 'invitation_revoked', 'organization_suspended', 'organization_unsuspended',
        'organization_opening_cancelled', 'organization_restored'] as $action) {
        check(in_array($action, $actions, true), "Missing audit event $action");
    }
    foreach ($audits as $entry) {
        $metadata = json_decode($entry['metadata'], true, flags: JSON_THROW_ON_ERROR);
        if (in_array($entry['action'], ['organization_created', 'organization_renamed', 'organization_suspended', 'organization_unsuspended',
            'organization_opening_cancelled', 'organization_restored', 'organization_owner_invitee_changed', 'invitation_reissued'], true)) {
            check((int) $entry['actor_account_id'] === $actor && $metadata['result'] === 'success', 'Platform audits identify the actor and result');
        }
        if ($entry['action'] === 'organization_owner_invitee_changed') {
            check($metadata['reason'] === 'recipient_changed' && $metadata['old_phone_masked'] === '**********9001'
                && $metadata['new_phone_masked'] === '**********9002', 'Recipient change audits the reason and both masked phones');
        }
        check(!str_contains($entry['metadata'], OLD_OWNER_PHONE) && !str_contains($entry['metadata'], NEW_OWNER_PHONE)
            && !str_contains($entry['metadata'], 'token=') && !str_contains($entry['metadata'], $cancelCredential),
            'Audit omits raw phones, links and verification credentials');
    }
}

function checkIssue19LiveSession(PDO $sql, int $port, array $headers, int $organization): void
{
    $path = OPENING_ROUTE . "/$organization/suspend";
    $sql->beginTransaction();
    $pending = null;
    try {
        $sql->query("SELECT id FROM starter_organizations WHERE id = $organization FOR UPDATE")->fetchColumn();
        $pending = smsConnect($port, 'POST', $path, headers: $headers);
        issue19AwaitLock($sql, 'starter_organizations');
        issue19Expect(smsRequest($port, 'POST', '/saas/auth/logout', headers: $headers), 200, 'success', 'Logout while the operation waits on the organization');
        $sql->commit();
        issue19Expect(smsReadResponse($pending), 401, 'token_invalid', 'Mutation rechecks the Redis session after obtaining the organization lock');
    } finally {
        if ($sql->inTransaction()) {
            $sql->rollBack();
        }
        if (is_resource($pending)) {
            fclose($pending);
        }
    }
    check($sql->query("SELECT suspended_at FROM starter_organizations WHERE id = $organization")->fetchColumn() === null,
        'A logged-out actor leaves the organization unchanged');
}

function checkIssue19PlatformEpoch(PDO $sql, int $port, array $headers, int $organization, int $actor): void
{
    $path = OPENING_ROUTE . "/$organization/suspend";
    $sql->beginTransaction();
    $pending = null;
    try {
        // The middleware sees the old epoch until commit. The locked qualification read must use the current epoch.
        $sql->exec("UPDATE starter_platform_admins SET session_epoch = session_epoch + 1 WHERE account_id = $actor");
        $pending = smsConnect($port, 'POST', $path, headers: $headers);
        issue19AwaitLock($sql, 'starter_platform_admins');
        $sql->commit();
        issue19Expect(smsReadResponse($pending), 401, 'token_invalid', 'Mutation rejects stale platform epoch under SQL lock');
    } finally {
        if ($sql->inTransaction()) {
            $sql->rollBack();
        }
        if (is_resource($pending)) {
            fclose($pending);
        }
    }
    check($sql->query("SELECT suspended_at FROM starter_organizations WHERE id = $organization")->fetchColumn() === null,
        'A stale platform actor leaves the organization unchanged');
}

$fixture = new Fixtures();
try {
    $qa = prepareD2Host($fixture, 'qa_opening');
    $sql = $fixture->database('qa_opening', create: false);
    $sql->exec("INSERT INTO starter_packages (name, seat_limit) VALUES ('One Seat', 1)");
    $package = (int) $sql->lastInsertId();
    $account = $sql->prepare('INSERT INTO starter_accounts (phone, created_at, updated_at) VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $accounts = [];
    foreach (['13800138000', '13800138001', OLD_OWNER_PHONE, NEW_OWNER_PHONE] as $phone) {
        $account->execute(['+86' . $phone]);
        $accounts[$phone] = (int) $sql->lastInsertId();
    }
    $actor = $accounts['13800138000'];
    $sql->exec("INSERT INTO starter_platform_admins (account_id, status, created_at, updated_at) VALUES ($actor, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $sql->exec("INSERT INTO starter_organizations (name, activated_at, subscription_starts_at, subscription_expires_at, seat_limit) "
        . "VALUES ('Active Org', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-02 00:00:00', 3)");
    $existing = (int) $sql->lastInsertId();
    $sql->prepare("INSERT INTO starter_memberships (organization_id, account_id, role) VALUES (?, ?, 'admin')")
        ->execute([$existing, $accounts['13800138001']]);
    $page = 'https://product.example.test/join';
    $qa->withHost('platform-opening', static function (int $port) use ($qa, $sql, $package, $page, $accounts, $actor): void {
        $organizationAuth = sessionBearer(smsToken(smsLogin($port, $qa->issue($port, '13800138001'), phone: '13800138001', purpose: 'organization')));
        $qa->clock(SMS_TIME + 61);
        $platformAuth = static fn (): array => sessionBearer(smsToken(smsLogin($port, $qa->issue($port, '13800138000'),
            phone: '13800138000', purpose: 'platform')));
        $platformHeaders = $platformAuth();
        $request = static fn (string $method, string $path, ?array $body = null, ?array $headers = null): array =>
            smsRequest($port, $method, $path, $body, $headers ?? $platformHeaders);
        [$opened, $upcoming] = checkIssue19Open($sql, $request, $package, $page, $organizationAuth);
        checkIssue19Lifecycle($qa, $sql, $port, $request, $opened, $accounts[OLD_OWNER_PHONE], $accounts[NEW_OWNER_PHONE], $actor, $organizationAuth);

        $upcomingPath = OPENING_ROUTE . '/' . $upcoming['organization_id'] . '/owner-invitations/' . $upcoming['invitation_id'];
        $sql->prepare('UPDATE starter_invitations SET expires_at = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s', SMS_TIME + 186), $upcoming['invitation_id']]);
        issue19Expect($request('GET', "$upcomingPath/link"), 409, 'invitation_invalid', 'Expired link cannot be retrieved');
        $renewed = issue19Expect($request('POST', "$upcomingPath/reissue"), 201, 'success', 'Reissue an expired invite before subscription starts');
        check($renewed['link'] !== $upcoming['link'] && $renewed['expires_at'] === gmdate(DATE_ATOM, SMS_TIME + 186 + 604800),
            'Expired invite is replaced with a new seven-day link');

        checkIssue19LiveSession($sql, $port, $platformHeaders, $upcoming['organization_id']);
        $qa->clock(SMS_TIME + 247);
        checkIssue19PlatformEpoch($sql, $port, $platformAuth(), $upcoming['organization_id'], $actor);
    }, ['STARTER_INVITATION_PAGE_URL' => $page]);
    echo "Issue #19 platform opening, owner invite lifecycle, recovery, audit rollback and session races passed on the installed Webman example\n";
} finally {
    $fixture->close();
}
