<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

require __DIR__ . '/d3-fixtures.php';

function auditQueryAcceptance(SmsLoginFixture $qa, PDO $db, int $port): void
{
    $admin = d3Login($qa, $port, PHONE, 'platform');
    $platform = '/platform/audit-logs';
    $package = d3Request($port, 'POST', '/platform/packages', ['name' => '审计测试', 'seat_limit' => 5], $admin, 201)['package_id'];
    $ownerPhone = '13900139111';
    $memberPhone = '13900139112';
    $otherPhone = '13900139113';
    $expiresAt = SMS_TIME + 180;
    $alpha = d3Open($port, $admin, $package, '甲组织', $ownerPhone, SMS_TIME + 10, $expiresAt);
    $beta = d3Open($port, $admin, $package, '乙组织', $otherPhone);
    $pending = d3Open($port, $admin, $package, '待开通', '13900139114');
    $id = $alpha['organization_id'];
    $org = "/organizations/$id";
    $orgAudit = $org . '/audit-logs';
    $ops = '/platform' . $org;
    $owner = d3Join($port, $qa, $alpha['link'], $ownerPhone, $id, 'owner');
    $other = d3Join($port, $qa, $beta['link'], $otherPhone, $beta['organization_id'], 'owner');
    d3Request($port, 'GET', $orgAudit, access: $owner, status: 403, code: 'subscription_inactive');
    $qa->clock(SMS_TIME + 10);
    d3Request($port, 'PATCH', $ops . '/name', ['name' => '甲组织改名'], $admin);
    $invite = d3Request($port, 'POST', $org . '/invitations', ['phone' => $memberPhone], $owner, 201);
    d3Request($port, 'GET', $org . '/invitations/' . $invite['invitation_id'] . '/link', access: $owner);
    $invite = d3Request($port, 'POST', $org . '/invitations/' . $invite['invitation_id'] . '/reissue', access: $owner, status: 201);
    $member = d3Join($port, $qa, $invite['link'], $memberPhone, $id, 'member');
    $members = d3Request($port, 'GET', $org . '/members', access: $owner)['members'];
    $memberId = array_values(array_filter($members, static fn (array $m): bool => $m['role'] === 'member'))[0]['account_id'];
    $ownerId = array_values(array_filter($members, static fn (array $m): bool => $m['role'] === 'owner'))[0]['account_id'];
    $ownerTransferPath = $org . '/ownership-transfers';
    $transfer = d3Request($port, 'POST', $ownerTransferPath, ['recipient_account_id' => $memberId], $owner, 201);
    d3Request($port, 'DELETE', $ownerTransferPath . '/' . $transfer['transfer_id'], access: $owner);
    $transfer = d3Request($port, 'POST', $ownerTransferPath, ['recipient_account_id' => $memberId], $owner, 201);
    // Materialize the historical expiry through its normal governance caller without expiring the session clock.
    $db->prepare('UPDATE starter_ownership_transfers SET expires_at = ? WHERE id = ?')
        ->execute([gmdate('Y-m-d H:i:s', SMS_TIME + 10), $transfer['transfer_id']]);
    d3Request($port, 'GET', $ownerTransferPath . '/pending', access: $member);
    d3Request($port, 'GET', $orgAudit, status: 401, code: 'unauthenticated');
    d3Request($port, 'GET', $orgAudit, access: $member, status: 403, code: 'forbidden');
    d3Request($port, 'PATCH', $org . '/members/' . $memberId . '/role', ['role' => 'admin'], $owner);
    d3Request($port, 'GET', $orgAudit, access: $member, status: 403, code: 'forbidden');
    d3Request($port, 'GET', $orgAudit, access: $other, status: 404, code: 'not_found');
    d3Request($port, 'GET', '/organizations/999999/audit-logs', access: $owner, status: 404, code: 'not_found');
    d3Request($port, 'GET', $orgAudit, access: $admin, status: 403, code: 'token_purpose_mismatch');
    d3Request($port, 'GET', $platform, access: $owner, status: 403, code: 'token_purpose_mismatch');

    $name = d3Request($port, 'GET', $orgAudit . '?action=organization_renamed', access: $owner)['items'][0];
    check($name['organization_id'] === $id && $name['actor_account_id'] !== $ownerId && $name['result'] === 'success'
        && $name['metadata'] === ['old_name' => '甲组织', 'new_name' => '甲组织改名'], 'The recorded rename contains its original actor, organization and values');
    $removed = d3Request($port, 'GET', $orgAudit . '?action=ownership_transfer_revoked', access: $owner)['items'][0];
    check($removed['actor_account_id'] === $ownerId && $removed['target_account_id'] === $memberId, 'Ownership revocation identifies the actor and target');
    $expired = d3Request($port, 'GET', $orgAudit . '?action=ownership_transfer_expired', access: $owner)['items'][0];
    check($expired['actor_account_id'] === null && $expired['result'] === 'expired', 'System expiry has no account actor');
    $grant = d3Request($port, 'POST', '/platform/admins', ['phone' => '13900139115'], $admin, 201);
    check(d3Request($port, 'GET', $platform . '?action=platform_admin_granted', access: $admin)['items'][0]['target_account_id'] === $grant['account_id'],
        'Staff account creation is visible in platform audit');
    $staff = d3Login($qa, $port, '13900139115', 'platform');
    d3Request($port, 'DELETE', '/platform/admins/' . $grant['account_id'], access: $admin);
    d3Request($port, 'GET', $platform, access: $staff, status: 403, code: 'forbidden');
    d3Request($port, 'POST', '/platform/organizations/' . $pending['organization_id'] . '/cancel-opening', access: $admin);
    check(d3Request($port, 'GET', $platform . '?action=organization_opening_cancelled', access: $admin)['items'][0]['metadata']['phase'] === 'pending',
        'Cancelling onboarding preserves the pending phase in platform audit');

    $insert = $db->prepare('INSERT INTO starter_audit_logs (organization_id, actor_account_id, target_account_id, action, metadata, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([null, $ownerId, $ownerId, 'phone_changed', json_encode([
        'method' => 'manual_recovery', 'old_phone_masked' => '**********9111', 'new_phone_masked' => '**********9119',
        'evidence_channel' => 'registered_contact', 'evidence_ref' => 'case-12345', 'password' => 'private-test-value',
        'phone' => '+8613900139111', 'verification_code' => '123456', 'token' => 'private-token-value',
    ], JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s', SMS_TIME + 10)]);
    $insert->execute([null, $ownerId, null, 'organization_renamed', '{"old_name":"unscoped record"}', gmdate('Y-m-d H:i:s', SMS_TIME + 10)]);
    $recovery = d3Request($port, 'GET', $platform . '?action=phone_changed', access: $admin)['items'][0];
    check($recovery['metadata'] === [
        'method' => 'manual_recovery', 'old_phone_masked' => '**********9111', 'new_phone_masked' => '**********9119',
        'evidence_channel' => 'registered_contact', 'evidence_ref' => 'case-12345',
    ], 'Platform projection exposes masked numbers and the controlled evidence reference only');
    check(d3Request($port, 'GET', $orgAudit . '?action=phone_changed', access: $owner)['items'] === [], 'Organization audit excludes global identity activity even for its own owner');
    check(count(d3Request($port, 'GET', $orgAudit . '?action=organization_renamed', access: $owner)['items']) === 1,
        'Organization audit excludes legacy records whose organization cannot be verified');

    $count = (int) $db->query('SELECT COUNT(*) FROM starter_audit_logs')->fetchColumn();
    $full = d3Request($port, 'GET', $orgAudit . '?per_page=100&organization_id=' . $beta['organization_id'], access: $owner);
    $first = d3Request($port, 'GET', $orgAudit . '?per_page=2', access: $owner);
    $second = d3Request($port, 'GET', $orgAudit . '?page=2&per_page=2', access: $owner);
    check(array_column($first['items'], 'id') === array_slice(array_column($full['items'], 'id'), 0, 2)
        && array_column($second['items'], 'id') === array_slice(array_column($full['items'], 'id'), 2, 2)
        && $first['pagination'] === ['page' => 1, 'per_page' => 2, 'total' => count($full['items']), 'total_pages' => (int) ceil(count($full['items']) / 2)]
        && array_unique(array_column($full['items'], 'organization_id')) === [$id], 'Audit pagination is stable and query parameters cannot override the route scope');
    d3Request($port, 'GET', $orgAudit . '?per_page=101', access: $owner, status: 422, code: 'validation_failed');
    d3Request($port, 'GET', $orgAudit . '?page[]=1', access: $owner, status: 422, code: 'validation_failed');
    d3Request($port, 'GET', $orgAudit . '?action=Unknown', access: $owner, status: 422, code: 'validation_failed');
    check((int) $db->query('SELECT COUNT(*) FROM starter_audit_logs')->fetchColumn() === $count, 'Listing audit logs does not create events');
    $qa->clock($expiresAt);
    d3Request($port, 'GET', $orgAudit, access: $owner);
    d3Request($port, 'GET', $orgAudit, access: $member, status: 403, code: 'forbidden');
    d3Request($port, 'POST', $ops . '/suspend', access: $admin);
    d3Request($port, 'GET', $orgAudit, access: $owner, status: 403, code: 'organization_suspended');
    d3Request($port, 'POST', $ops . '/archive', access: $admin);
    d3Request($port, 'GET', $orgAudit, access: $owner, status: 403, code: 'organization_archived');
    check(d3Request($port, 'GET', $platform . '?action=organization_archived', access: $admin)['items'][0]['organization_id'] === $id,
        'Platform operations remain visible when organization-side access is blocked');
}

$fixtures = new Fixtures();
try {
    $qa = prepareD2Host($fixtures, 'qa_auditquery');
    $db = $fixtures->database('qa_auditquery', create: false);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $qa->withHost('d4-audit', static fn (int $port) => auditQueryAcceptance($qa, $db, $port), D3_HOST_ENV);
    echo "PASS D4 audit queries, historical events, scope, privacy and lifecycle\n";
} finally {
    $fixtures->close();
}
