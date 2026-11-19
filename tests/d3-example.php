<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

require __DIR__ . '/d3-fixtures.php';

function d3ExampleWindow(int $port, string $admin, string $platformPath, int $start, int $end): array
{
    return d3Request($port, 'PATCH', $platformPath . '/subscription/window', [
        'starts_at' => gmdate('Y-m-d\TH:i:s', $start + 8 * 3600) . '+08:00',
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $end),
    ], $admin);
}

function d3ExampleReservations(PDO $sql, int $organizationId): int
{
    $query = $sql->prepare('SELECT COUNT(*) FROM starter_invitations WHERE organization_id = ? AND reserved = 1');
    $query->execute([$organizationId]);

    return (int) $query->fetchColumn();
}

function d3ExampleActive(SmsLoginFixture $qa, PDO $sql, int $port, string $admin, int $original, int $small): void
{
    $ownerPhone = '13900139101';
    $memberPhone = '13900139102';
    $pendingPhone = '13900139103';
    $start = SMS_TIME + 100;
    $end = SMS_TIME + 300;
    $alpha = d3Open($port, $admin, $original, '甲组织', $ownerPhone, $start, $end);
    $beta = d3Open($port, $admin, $small, '乙组织', $ownerPhone);
    $id = $alpha['organization_id'];
    $path = "/organizations/$id";
    $platformPath = '/platform' . $path;
    $records = $path . '/records';
    $betaRecords = '/organizations/' . $beta['organization_id'] . '/records';
    $owner = d3Join($port, $qa, $alpha['link'], $ownerPhone, $id, 'owner');
    $packagePath = "/platform/packages/$original";
    d3Request($port, 'PATCH', $packagePath, ['name' => '套餐新名称', 'seat_limit' => 1], $admin);
    d3Request($port, 'POST', $packagePath . '/retire', access: $admin);
    d3ExampleWindow($port, $admin, $platformPath, $start, $end + 1);
    $window = d3ExampleWindow($port, $admin, $platformPath, $start, $end);
    check($window['starts_at'] === gmdate(DATE_ATOM, $start) && $window['expires_at'] === gmdate(DATE_ATOM, $end),
        'Different UTC offsets use the same second-precision window');

    $qa->clock($start - 1);
    d3Request($port, 'GET', $records, access: $owner, status: 403, code: 'subscription_inactive');
    d3Request($port, 'POST', $records, ['title' => '尚未生效'], $owner, 403, 'subscription_inactive');
    d3Request($port, 'POST', $path . '/invitations', ['phone' => $memberPhone], $owner, 403, 'subscription_inactive');
    d3Request($port, 'GET', $path . '/members', access: $owner, status: 403, code: 'subscription_inactive');
    $qa->clock($start);
    $record = d3Request($port, 'POST', $records, ['title' => '生效秒创建'], $owner, 201);
    $recordPath = $records . '/' . $record['id'];
    $betaAccepted = d3Request($port, 'POST', '/invitations/accept', d3InvitationProof($qa, $port, $beta['link'], $ownerPhone));
    check($betaAccepted['organization_id'] === $beta['organization_id'], 'The same global account can join the second organization');
    d3Request($port, 'POST', $betaRecords, ['title' => '乙组织记录'], $owner, 201);
    $invite = static fn (string $phone): array => d3Request($port, 'POST', $path . '/invitations', ['phone' => $phone], $owner, 201);
    $join = $invite($memberPhone);
    $member = d3Join($port, $qa, $join['link'], $memberPhone, $id, 'member');
    $pending = $invite($pendingPhone);
    $proof = d3InvitationProof($qa, $port, $pending['link'], $pendingPhone);
    $revoke = $invite('13900139104');
    check(d3ExampleReservations($sql, $id) === 2, 'Template edit and retirement preserve the original capacity and reservations');

    $qa->clock($end - 1);
    d3Request($port, 'PATCH', $recordPath, ['title' => '到期前更新'], $owner);
    $qa->clock($end);
    check(d3Request($port, 'GET', $recordPath, access: $member)['title'] === '到期前更新',
        'Existing members retain record reads at the exact expiry second');
    d3Request($port, 'PATCH', $recordPath, ['title' => '到期后写入'], $owner, 403, 'subscription_expired');
    d3Request($port, 'POST', $path . '/invitations', ['phone' => '13900139105'], $owner, 403, 'subscription_expired');
    d3Request($port, 'POST', '/invitations/accept', $proof, status: 403, code: 'subscription_expired');
    d3Request($port, 'DELETE', $path . '/invitations/' . $revoke['invitation_id'], access: $member, status: 403, code: 'forbidden');
    d3Request($port, 'DELETE', $path . '/invitations/' . $revoke['invitation_id'], access: $owner);
    d3Request($port, 'PATCH', $platformPath . '/subscription/package', ['package_id' => $original], $admin, 409, 'package_retired');
    d3Request($port, 'PATCH', $platformPath . '/subscription/package', ['package_id' => $small], $admin, 409, 'seat_limit_exceeded');

    d3Request($port, 'POST', $platformPath . '/suspend', access: $admin);
    d3Request($port, 'GET', $recordPath, access: $owner, status: 403, code: 'organization_suspended');
    d3Request($port, 'POST', '/invitations/accept', $proof, status: 403, code: 'organization_suspended');
    d3Request($port, 'POST', $platformPath . '/archive', access: $admin);
    d3Request($port, 'GET', $recordPath, access: $owner, status: 403, code: 'organization_archived');
    $freshOwner = d3Login($qa, $port, $ownerPhone);
    d3Request($port, 'GET', '/account/profile', access: $freshOwner);
    check(array_column(d3Request($port, 'GET', $betaRecords, access: $freshOwner)['records'], 'title') === ['乙组织记录'],
        'The archived organization leaves global login and the same account’s other organization available');

    $replacement = d3Request($port, 'PATCH', $platformPath . '/subscription/package', ['package_id' => $small], $admin);
    check($replacement['seat_limit'] === 2 && d3ExampleReservations($sql, $id) === 0,
        'The archived organization fits two seats after its last reservation is released');
    $restored = d3Request($port, 'POST', $platformPath . '/restore', access: $admin);
    check($restored['phase'] === 'active' && $restored['suspended'], 'Restore the activation phase and preserve suspension');
    d3Request($port, 'GET', $recordPath, access: $owner, status: 403, code: 'organization_suspended');
    d3Request($port, 'POST', $platformPath . '/unsuspend', access: $admin);
    d3Request($port, 'GET', $recordPath, access: $member);
    d3Request($port, 'PATCH', $recordPath, ['title' => '恢复后仍到期'], $owner, 403, 'subscription_expired');
    d3Request($port, 'POST', '/invitations/accept', $proof, status: 409, code: 'invitation_invalid');
    check(d3ExampleReservations($sql, $id) === 0, 'Restoring does not revive a revoked proof or its seat');

    $audits = $sql->prepare('SELECT action FROM starter_audit_logs WHERE organization_id = ? ORDER BY id');
    $audits->execute([$id]);
    $tracked = ['subscription_window_updated', 'organization_suspended', 'organization_archived',
        'subscription_package_changed', 'organization_restored', 'organization_unsuspended'];
    check(array_values(array_intersect($audits->fetchAll(PDO::FETCH_COLUMN), $tracked)) === [$tracked[0], ...$tracked],
        'Audit includes both window changes and the committed lifecycle and package transitions in order');
}

function d3ExamplePending(SmsLoginFixture $qa, PDO $sql, int $port, string $admin, int $package): void
{
    $phone = '13900139106';
    $now = SMS_TIME + 300;
    $opened = d3Open($port, $admin, $package, '待开通组织', $phone, $now + 60, $now + 600);
    $id = $opened['organization_id'];
    $platformPath = "/platform/organizations/$id";
    $reissue = $platformPath . '/owner-invitations/' . $opened['invitation_id'] . '/reissue';
    $proof = d3InvitationProof($qa, $port, $opened['link'], $phone);
    d3Request($port, 'POST', $platformPath . '/suspend', access: $admin);
    d3Request($port, 'POST', '/invitations/accept', $proof, status: 403, code: 'organization_suspended');
    d3Request($port, 'POST', $platformPath . '/archive', access: $admin);
    d3Request($port, 'POST', $reissue, access: $admin, status: 403, code: 'organization_archived');
    d3ExampleWindow($port, $admin, $platformPath, $now - 60, $now - 1);
    $restored = d3Request($port, 'POST', $platformPath . '/restore', access: $admin);
    check($restored['phase'] === 'pending' && $restored['suspended'] && d3ExampleReservations($sql, $id) === 0,
        'An expired pending organization restores to pending and suspended without a seat reservation');
    d3Request($port, 'POST', $platformPath . '/unsuspend', access: $admin);
    d3Request($port, 'POST', $reissue, access: $admin, status: 403, code: 'subscription_expired');
    d3Request($port, 'POST', '/invitations/accept', $proof, status: 409, code: 'invitation_invalid');
    d3ExampleWindow($port, $admin, $platformPath, $now + 60, $now + 600);
    $reissued = d3Request($port, 'POST', $reissue, access: $admin, status: 201);
    check($reissued['invitation_id'] !== $opened['invitation_id'] && d3ExampleReservations($sql, $id) === 1,
        'Only a separately authorized reissue creates a new owner invitation and reservation');
    $qa->clock($now + 60);
    $owner = d3Join($port, $qa, $reissued['link'], $phone, $id, 'owner');
    d3Request($port, 'GET', "/organizations/$id/records", access: $owner);
    check(d3ExampleReservations($sql, $id) === 0, 'The new invitation converts its reservation into an owner seat');
}

$fixtures = new Fixtures();
try {
    $qa = prepareD2Host($fixtures, 'qa_lifecycleexample');
    $sql = $fixtures->database('qa_lifecycleexample', create: false);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $fixtures->run([PHP_BINARY, 'bin/setup-example.php'], 'Set up example records', $fixtures->host);
    $qa->withHost('d3-example', static function (int $port) use ($qa, $sql): void {
        $admin = d3Login($qa, $port, PHONE, 'platform');
        $original = d3Request($port, 'POST', '/platform/packages', ['name' => '初始套餐', 'seat_limit' => 4], $admin, 201)['package_id'];
        $small = d3Request($port, 'POST', '/platform/packages', ['name' => '两席套餐', 'seat_limit' => 2], $admin, 201)['package_id'];
        d3ExampleActive($qa, $sql, $port, $admin, $original, $small);
        d3ExamplePending($qa, $sql, $port, $admin, $small);
    }, D3_HOST_ENV);
    echo "PASS D3 Webman example package changes, exact time boundaries, seat release and lifecycle restoration
";
} finally {
    $fixtures->close();
}
