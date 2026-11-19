<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

require __DIR__ . '/d3-fixtures.php';

function issue30Subscription(PDO $sql, int $id): array
{
    return $sql->query("SELECT subscription_starts_at, subscription_expires_at, package_id, package_name, seat_limit
        FROM starter_organizations WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
}

function issue30Audits(PDO $sql, int $id, array $expected): void
{
    $rows = $sql->query("SELECT action, actor_account_id, metadata FROM starter_audit_logs WHERE organization_id = $id
        AND action IN ('organization_archived', 'organization_restored', 'invitation_reissued') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $actual = array_map(static function (array $row): array {
        $metadata = json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR);

        return [$row['action'], (int) $row['actor_account_id'], $metadata['phase'] ?? null, $metadata['suspended'] ?? null];
    }, $rows);
    check($actual === $expected, 'Audit attributes each committed lifecycle change or reissue to its actual actor');
}

function issue30Active(SmsLoginFixture $qa, PDO $sql, int $port, int $packageId, string $platform, int $platformId): void
{
    $ownerPhone = '13900139001';
    $memberPhone = '13900139002';
    $invitedPhone = '13900139003';
    $expiredPhone = '13900139004';
    $opened = d3Open($port, $platform, $packageId, '归档验收', $ownerPhone);
    $id = (int) $opened['organization_id'];
    $owner = d3Join($port, $qa, $opened['link'], $ownerPhone, $id, 'owner');
    $organization = "/organizations/$id";
    $admin = '/platform' . $organization;
    $archive = $organization . '/archive';
    $invite = static fn (string $phone): array => d3Request($port, 'POST', $organization . '/invitations', ['phone' => $phone], $owner, 201);
    $joinedInvite = $invite($memberPhone);
    $member = d3Join($port, $qa, $joinedInvite['link'], $memberPhone, $id, 'member');
    $members = d3Request($port, 'GET', $organization . '/members', access: $owner)['members'];
    $memberId = $members[array_search('member', array_column($members, 'role'), true)]['account_id'];
    $ownerId = $members[array_search('owner', array_column($members, 'role'), true)]['account_id'];
    $transfer = d3Request($port, 'POST', $organization . '/ownership-transfers', ['recipient_account_id' => $memberId], $owner, 201);
    $inbox = $organization . '/ownership-transfers/pending';
    check(array_column(d3Request($port, 'GET', $inbox, access: $member)['transfers'], 'transfer_id') === [$transfer['transfer_id']],
        'The recipient has an outstanding transfer before archival');
    $record = d3Request($port, 'POST', $organization . '/records', ['title' => '需要保留的数据'], $owner, 201);
    $recordPath = $organization . '/records/' . $record['id'];
    $subscription = issue30Subscription($sql, $id);
    $pending = $invite($invitedPhone);
    $proof = d3InvitationProof($qa, $port, $pending['link'], $invitedPhone);
    $expired = $invite($expiredPhone);
    $sql->prepare('UPDATE starter_invitations SET expires_at = ? WHERE organization_id = ? AND id = ?')
        ->execute([gmdate('Y-m-d H:i:s', SMS_TIME), $id, $expired['invitation_id']]);

    d3Request($port, 'POST', $archive, status: 401, code: 'unauthenticated');
    d3Request($port, 'POST', $archive, access: $platform, status: 403, code: 'token_purpose_mismatch');
    d3Request($port, 'POST', $admin . '/archive', access: $owner, status: 403, code: 'token_purpose_mismatch');
    d3Request($port, 'POST', $archive, access: $member, status: 403, code: 'forbidden');
    $outsider = d3Login($qa, $port, OTHER_PHONE);
    d3Request($port, 'POST', $archive, access: $outsider, status: 404, code: 'not_found');

    $sql->exec("CREATE TRIGGER qa_archive_reject_audit BEFORE INSERT ON starter_audit_logs FOR EACH ROW
        BEGIN IF NEW.action = 'organization_archived' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QA rollback'; END IF; END");
    try {
        d3Request($port, 'POST', $archive, access: $owner, status: 503, code: 'audit_unavailable');
        d3Request($port, 'POST', $admin . '/archive', access: $platform, status: 503, code: 'audit_unavailable');
        check(d3Request($port, 'GET', $recordPath, access: $owner)['title'] === '需要保留的数据',
            'Failed audit restores access by rolling back the archive marker');
        d3Request($port, 'POST', '/invitations/resolve', ['token' => $proof['token']]);
    } finally {
        $sql->exec('DROP TRIGGER qa_archive_reject_audit');
    }

    $archived = d3Request($port, 'POST', $archive, access: $owner);
    check($archived === ['archived' => true, 'organization_id' => $id, 'name' => '归档验收',
        'phase' => 'active', 'suspended' => false], 'The active, unsuspended owner can archive');
    $invites = $sql->query("SELECT status, reserved FROM starter_invitations WHERE organization_id = $id ORDER BY id")->fetchAll(PDO::FETCH_NUM);
    check($invites === [['accepted', 0], ['accepted', 0], ['revoked', 0], ['revoked', 0]],
        'Archived organization preserves consumed invitations and revokes even expired unconsumed invitations');
    d3Request($port, 'POST', $archive, access: $owner, status: 403, code: 'organization_archived');
    d3Request($port, 'POST', $archive, access: $outsider, status: 404, code: 'not_found');
    d3Request($port, 'GET', $recordPath, access: $member, status: 403, code: 'organization_archived');
    d3Request($port, 'GET', $inbox, access: $member, status: 403, code: 'organization_archived');
    d3Request($port, 'POST', $organization . '/ownership-transfers/' . $transfer['transfer_id'] . '/accept',
        access: $member, status: 403, code: 'organization_archived');
    d3Request($port, 'GET', '/account/profile', access: $owner);
    d3Request($port, 'POST', $admin . '/restore', access: $owner, status: 403, code: 'token_purpose_mismatch');

    $sql->exec("CREATE TRIGGER qa_archive_reject_audit BEFORE INSERT ON starter_audit_logs FOR EACH ROW
        BEGIN IF NEW.action = 'organization_restored' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QA rollback'; END IF; END");
    try {
        d3Request($port, 'POST', $admin . '/restore', access: $platform, status: 503, code: 'audit_unavailable');
        d3Request($port, 'GET', $recordPath, access: $owner, status: 403, code: 'organization_archived');
    } finally {
        $sql->exec('DROP TRIGGER qa_archive_reject_audit');
    }
    $restored = d3Request($port, 'POST', $admin . '/restore', access: $platform);
    check($restored === ['archived' => false] + $archived && issue30Subscription($sql, $id) === $subscription,
        'Restore preserves the original activation, suspension and subscription');
    check(d3Request($port, 'GET', $organization . '/members', access: $owner)['members'] === $members
        && d3Request($port, 'GET', $recordPath, access: $member)['title'] === '需要保留的数据',
        'Restore preserves the unique owner, membership and business data');
    d3Request($port, 'POST', '/invitations/accept', $proof, status: 409, code: 'invitation_invalid');
    $newInvitation = $invite($invitedPhone);
    check($newInvitation['invitation_id'] !== $pending['invitation_id']
        && $invite($expiredPhone)['invitation_id'] !== $expired['invitation_id'], 'New reservations can use both released seats');

    d3Request($port, 'POST', $admin . '/suspend', access: $platform);
    issue30RevokeSuspendedInvitation($sql, $port, $id, $newInvitation, $owner, $platform, $platformId);
    d3Request($port, 'POST', $archive, access: $owner, status: 403, code: 'organization_suspended');
    $archivedSuspended = d3Request($port, 'POST', $admin . '/archive', access: $platform);
    check($archivedSuspended === array_replace($archived, ['suspended' => true]), 'Platform can archive a suspended active organization');
    d3Request($port, 'POST', $archive, access: $owner, status: 403, code: 'organization_archived');
    $restoredSuspended = d3Request($port, 'POST', $admin . '/restore', access: $platform);
    check($restoredSuspended === ['archived' => false] + $archivedSuspended, 'Restore retains the suspension of an active organization');
    d3Request($port, 'GET', $recordPath, access: $owner, status: 403, code: 'organization_suspended');
    d3Request($port, 'POST', $admin . '/unsuspend', access: $owner, status: 403, code: 'token_purpose_mismatch');
    d3Request($port, 'POST', $admin . '/unsuspend', access: $platform);
    d3Request($port, 'GET', $recordPath, access: $owner);
    issue30Audits($sql, $id, [
        ['organization_archived', $ownerId, 'active', false],
        ['organization_restored', $platformId, 'active', false],
        ['organization_archived', $platformId, 'active', true],
        ['organization_restored', $platformId, 'active', true],
    ]);

    $updateWindow = $sql->prepare('UPDATE starter_organizations SET subscription_starts_at = ?, subscription_expires_at = ? WHERE id = ?');
    $updateWindow->execute([gmdate('Y-m-d H:i:s', SMS_TIME + 300), $subscription['subscription_expires_at'], $id]);
    d3Request($port, 'POST', $archive, access: $owner, status: 403, code: 'subscription_inactive');
    d3Request($port, 'POST', $admin . '/archive', access: $platform);
    $updateWindow->execute([$subscription['subscription_starts_at'], gmdate('Y-m-d H:i:s', SMS_TIME), $id]);
    d3Request($port, 'POST', $archive, access: $owner, status: 403, code: 'organization_archived');
    d3Request($port, 'POST', $admin . '/restore', access: $platform);
    d3Request($port, 'GET', $recordPath, access: $member);
    d3Request($port, 'PATCH', $recordPath, ['title' => '不应写入'], $owner, 403, 'subscription_expired');
    d3Request($port, 'POST', $archive, access: $owner, status: 403, code: 'subscription_expired');
    $updateWindow->execute([$subscription['subscription_starts_at'], $subscription['subscription_expires_at'], $id]);

    issue30RechecksOwnership($sql, $port, $id, $owner, $ownerId, $memberId);
    d3Request($port, 'POST', $archive, access: $member);
}

/** @param array{invitation_id:int, link:string} $invitation */
function issue30RevokeSuspendedInvitation(
    PDO $sql,
    int $port,
    int $organizationId,
    array $invitation,
    string $owner,
    string $platform,
    int $platformId,
): void {
    $invitationId = $invitation['invitation_id'];
    $path = "/organizations/$organizationId/invitations/$invitationId";
    $platformPath = '/platform' . $path;
    $preview = ['token' => d3Token($invitation['link'])];
    d3Request($port, 'DELETE', $path, access: $owner, status: 403, code: 'organization_suspended');
    d3Request($port, 'DELETE', $platformPath, access: $owner, status: 403, code: 'token_purpose_mismatch');

    $sql->exec("CREATE TRIGGER qa_revoke_reject_audit BEFORE INSERT ON starter_audit_logs FOR EACH ROW
        BEGIN IF NEW.action = 'invitation_revoked' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QA rollback'; END IF; END");
    try {
        d3Request($port, 'DELETE', $platformPath, access: $platform, status: 503, code: 'audit_unavailable');
        d3Request($port, 'POST', '/invitations/resolve', $preview);
        check($sql->query("SELECT reserved FROM starter_invitations WHERE id = $invitationId")->fetchColumn() === 1,
            'A failed audit preserves the reservation');
    } finally {
        $sql->exec('DROP TRIGGER qa_revoke_reject_audit');
    }

    d3Request($port, 'DELETE', $platformPath, access: $platform);
    d3Request($port, 'POST', '/invitations/resolve', $preview, status: 409, code: 'invitation_invalid');
    check($sql->query("SELECT status, reserved FROM starter_invitations WHERE id = $invitationId")->fetch(PDO::FETCH_NUM) === ['revoked', 0],
        'Revoke releases the reservation without lifting the suspension');
    d3Request($port, 'GET', "/organizations/$organizationId/members", access: $owner, status: 403, code: 'organization_suspended');
    $events = $sql->query("SELECT actor_account_id, metadata FROM starter_audit_logs WHERE organization_id = $organizationId
        AND action = 'invitation_revoked'")->fetchAll(PDO::FETCH_ASSOC);
    check(count($events) === 1 && (int) $events[0]['actor_account_id'] === $platformId
        && json_decode($events[0]['metadata'], true, flags: JSON_THROW_ON_ERROR)['invitation_id'] === $invitationId,
        'The successful revoke records exactly one audit with the platform actor and invitation');
}

function issue30RechecksOwnership(PDO $sql, int $port, int $id, string $oldOwner, int $ownerId, int $newOwnerId): void
{
    $sql->beginTransaction();
    $client = null;
    try {
        $sql->query("SELECT id FROM starter_organizations WHERE id = $id FOR UPDATE")->fetchColumn();
        $client = smsConnect($port, 'POST', "/saas/organizations/$id/archive", null, sessionBearer($oldOwner));
        $waiting = $sql->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST '
            . 'WHERE ID <> CONNECTION_ID() AND DB = DATABASE() AND INFO LIKE ?');
        $deadline = microtime(true) + 3;
        do {
            $waiting->execute(['%from %starter_organizations%for update%']);
            if ((int) $waiting->fetchColumn() > 0) {
                break;
            }
            check(microtime(true) < $deadline, 'Owner archive must wait for the held organization lock');
            usleep(1000);
        } while (true);

        $updateRole = $sql->prepare('UPDATE starter_memberships SET role = ? WHERE organization_id = ? AND account_id = ?');
        $updateRole->execute(['admin', $id, $ownerId]);
        $updateRole->execute(['owner', $id, $newOwnerId]);
        $sql->commit();
        smsExpect(smsReadResponse($client), 403, 'forbidden', 'Previous owner loses the right to archive while waiting for the organization lock');
    } finally {
        if ($sql->inTransaction()) {
            $sql->rollBack();
        }
        if ($client !== null) {
            fclose($client);
        }
    }
}

function issue30Pending(SmsLoginFixture $qa, PDO $sql, int $port, int $packageId, string $platform, int $platformId): void
{
    $phone = '13900139005';
    $expiresAt = SMS_TIME + 600;
    $opened = d3Open($port, $platform, $packageId, '待激活归档', $phone, expiresAt: $expiresAt);
    $id = (int) $opened['organization_id'];
    $admin = "/platform/organizations/$id";
    $invitation = $admin . '/owner-invitations/' . $opened['invitation_id'];
    $subscription = issue30Subscription($sql, $id);
    $proof = d3InvitationProof($qa, $port, $opened['link'], $phone);
    d3Request($port, 'POST', $admin . '/suspend', access: $platform);
    $qa->clock($expiresAt);
    d3Request($port, 'POST', $invitation . '/reissue', access: $platform, status: 403, code: 'organization_suspended');
    $archived = d3Request($port, 'POST', $admin . '/archive', access: $platform);
    check($archived['phase'] === 'pending' && $archived['suspended'] && $archived['archived'],
        'Platform can archive an expired, suspended pending organization');
    d3Request($port, 'POST', $invitation . '/reissue', access: $platform, status: 403, code: 'organization_archived');
    $restored = d3Request($port, 'POST', $admin . '/restore', access: $platform);
    check($restored === ['archived' => false] + $archived && issue30Subscription($sql, $id) === $subscription,
        'Platform can restore an expired organization to pending and retain its suspension');
    check((int) $sql->query("SELECT COUNT(*) FROM starter_memberships WHERE organization_id = $id")->fetchColumn() === 0
        && $sql->query("SELECT status, reserved FROM starter_invitations WHERE organization_id = $id")->fetchAll(PDO::FETCH_NUM) === [['revoked', 0]],
        'No owner, effective invitation or reservation is created by pending restoration');
    d3Request($port, 'POST', $invitation . '/reissue', access: $platform, status: 403, code: 'organization_suspended');
    d3Request($port, 'POST', $admin . '/unsuspend', access: $platform);
    d3Request($port, 'POST', $invitation . '/reissue', access: $platform, status: 403, code: 'subscription_expired');
    d3Request($port, 'POST', '/invitations/accept', $proof, status: 409, code: 'invitation_invalid');

    // Use a fixture update to isolate the post-renewal behavior from the separate subscription-date feature.
    $sql->prepare('UPDATE starter_organizations SET subscription_expires_at = ? WHERE id = ?')
        ->execute([gmdate('Y-m-d H:i:s', $expiresAt + 3600), $id]);
    $reissued = d3Request($port, 'POST', $invitation . '/reissue', access: $platform, status: 201);
    check($reissued['invitation_id'] !== $opened['invitation_id'], 'Reissue allocates a new invitation after renewal');
    $owner = d3Join($port, $qa, $reissued['link'], $phone, $id, 'owner');
    check(array_column(d3Request($port, 'GET', "/organizations/$id/members", access: $owner)['members'], 'role') === ['owner'],
        'Only acceptance of the newly issued invitation activates the organization');
    issue30Audits($sql, $id, [
        ['organization_archived', $platformId, 'pending', true],
        ['organization_restored', $platformId, 'pending', true],
        ['invitation_reissued', $platformId, null, null],
    ]);
}

function issue30ArchiveWithoutInvitationPage(SmsLoginFixture $qa, int $packageId): void
{
    $platform = $owner = '';
    $id = 0;
    $qa->withHost('issue30-no-page', static function (int $port) use ($qa, $packageId, &$platform, &$owner, &$id): void {
        $platform = d3Login($qa, $port, PHONE, 'platform');
        $phone = '13900139006';
        $opened = d3Open($port, $platform, $packageId, '无邀请页归档', $phone);
        $id = $opened['organization_id'];
        $owner = d3Join($port, $qa, $opened['link'], $phone, $id, 'owner');
    }, D3_HOST_ENV);

    $qa->withHost('issue30-no-page', static function (int $port) use ($platform, $owner, $id): void {
        $organization = "/organizations/$id";
        $admin = '/platform' . $organization;
        $archive = $organization . '/archive';
        d3Request($port, 'GET', $organization . '/members', access: $owner);
        d3Request($port, 'POST', $archive, access: $owner);
        d3Request($port, 'GET', $organization . '/members', access: $owner, status: 403, code: 'organization_archived');
        d3Request($port, 'POST', $admin . '/restore', access: $platform);
        d3Request($port, 'POST', $admin . '/archive', access: $platform);
        d3Request($port, 'GET', $organization . '/members', access: $owner, status: 403, code: 'organization_archived');
    }, ['STARTER_INVITATION_PAGE_URL' => ''] + D3_HOST_ENV);
}

$fixtures = new Fixtures();
try {
    $qa = prepareD2Host($fixtures, 'qa_archive');
    $fixtures->run([PHP_BINARY, 'bin/setup-example.php'], 'Prepare the example business table', $fixtures->host);
    $sql = $fixtures->database('qa_archive', create: false);
    $sql->exec("INSERT INTO starter_packages (name, seat_limit) VALUES ('Archive test', 4)");
    $packageId = (int) $sql->lastInsertId();
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $account = $sql->prepare('SELECT id FROM starter_accounts WHERE phone = ?');
    $account->execute(['+86' . PHONE]);
    $platformId = (int) $account->fetchColumn();
    $sql->prepare('INSERT INTO starter_accounts (phone, created_at, updated_at) VALUES (?, ?, ?)')
        ->execute(['+86' . OTHER_PHONE, gmdate('Y-m-d H:i:s', SMS_TIME), gmdate('Y-m-d H:i:s', SMS_TIME)]);
    $qa->withHost('issue30', static function (int $port) use ($qa, $sql, $packageId, $platformId): void {
        $platform = d3Login($qa, $port, PHONE, 'platform');
        issue30Active($qa, $sql, $port, $packageId, $platform, $platformId);
        issue30Pending($qa, $sql, $port, $packageId, $platform, $platformId);
    }, D3_HOST_ENV);
    issue30ArchiveWithoutInvitationPage($qa, $packageId);
    echo "PASS issue #30 archive and restore through Webman, MySQL and Redis\n";
} finally {
    $fixtures->close();
}
