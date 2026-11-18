<?php

declare(strict_types=1);

namespace StarterAcceptance;

use Illuminate\Database\MySqlConnection;
use PDO;
use RuntimeException;
use Z0x\WebmanStarter\saas\membership\IlluminateMembershipRepository;
use Z0x\WebmanStarter\saas\membership\MembershipInvalidatorInterface;
use Z0x\WebmanStarter\saas\organization\IlluminateOrganizationRepository;

/** @return array<string, int> */
function seedIssue21(PDO $sql, int $now): array
{
    $date = gmdate('Y-m-d H:i:s', $now);
    $account = $sql->prepare('INSERT INTO starter_accounts (phone, display_name, email, email_verified_at, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?)');
    $ids = [];
    foreach ([
        'owner_a' => ['+8613800138000', '甲所有者', 'owner-a@example.test', $date],
        'admin' => ['+8613800138001', '甲管理员', 'unverified@example.test', null],
        'dual' => ['+8613800138002', null, null, null],
        'owner_b' => ['+8613800138003', '乙所有者', 'owner-b@example.test', $date],
        'ordinary' => ['+8613800138004', '甲成员', null, null],
    ] as $key => $row) {
        $account->execute([...$row, $date, $date]);
        $ids[$key] = (int) $sql->lastInsertId();
    }
    $sql->prepare("INSERT INTO starter_platform_admins (account_id, status, created_at, updated_at) VALUES (?, 'active', ?, ?)")
        ->execute([$ids['owner_a'], $date, $date]);
    $organization = $sql->prepare('INSERT INTO starter_organizations '
        . '(name, activated_at, suspended_at, archived_at, subscription_starts_at, subscription_expires_at, seat_limit) '
        . 'VALUES (?, ?, ?, ?, ?, ?, 10)');
    foreach ([
        'a' => ['甲组织', $date, null, null, $now - 30, $now + 600],
        'b' => ['乙组织', $date, null, null, $now - 30, $now + 600],
        'inactive' => ['未生效', $date, null, null, $now + 60, $now + 600],
        'expired' => ['已到期', $date, null, null, $now - 600, $now],
        'suspended' => ['已停用', $date, $date, null, $now + 60, $now + 600],
        'archived' => ['已归档', $date, $date, $date, $now + 60, $now + 600],
        'pending' => ['待激活', null, null, null, $now - 30, $now + 600],
    ] as $key => [$name, $active, $suspended, $archived, $start, $end]) {
        $organization->execute([$name, $active, $suspended, $archived, gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end)]);
        $ids[$key] = (int) $sql->lastInsertId();
    }
    $membership = $sql->prepare('INSERT INTO starter_memberships (organization_id, account_id, role) VALUES (?, ?, ?)');
    foreach (['a', 'inactive', 'expired', 'suspended', 'archived', 'pending'] as $key) {
        $membership->execute([$ids[$key], $ids['owner_a'], 'owner']);
        $membership->execute([$ids[$key], $ids['admin'], 'admin']);
        $membership->execute([$ids[$key], $ids['dual'], 'member']);
        $membership->execute([$ids[$key], $ids['ordinary'], 'member']);
    }
    $membership->execute([$ids['b'], $ids['owner_b'], 'owner']);
    $membership->execute([$ids['b'], $ids['dual'], 'member']);

    return $ids;
}

/** @param array<string, int> $ids */
function checkIssue21(PDO $sql, array $ids, callable $request): void
{
    $lastAuditId = (int) $sql->query('SELECT COALESCE(MAX(id), 0) FROM starter_audit_logs')->fetchColumn();
    $expect = static function (array $response, int $status, string $code, string $label): mixed {
        [$actualStatus, $body] = $response;
        $actualCode = $body['code'] ?? null;
        check($actualStatus === $status && $actualCode === $code,
            "$label: expected $status $code, got $actualStatus " . json_encode($body, JSON_UNESCAPED_UNICODE));
        check(is_string($body['trace_id'] ?? null) && $body['trace_id'] !== '', "$label: missing trace ID");

        return $body['data'];
    };
    $path = static fn (string $org): string => '/saas/organizations/' . $ids[$org] . '/members';
    $list = static fn (string $org, ?string $who): array => $request('GET', $path($org), $who);
    $remove = static fn (string $org, string $who, string $target): array => $request('DELETE', $path($org) . '/' . $ids[$target], $who);
    $exit = static fn (string $org, string $who): array => $request('DELETE', $path($org) . '/me', $who);
    $change = static fn (string $org, string $who, string $target, mixed $role): array => $request('PATCH',
        $path($org) . '/' . $ids[$target] . '/role', $who, ['role' => $role]);

    $a = $expect($list('a', 'dual'), 200, 'success', 'Member listing succeeds');
    check($a === ['organization_id' => $ids['a'], 'name' => '甲组织', 'members' => [
        ['display_name' => '甲所有者', 'role' => 'owner'],
        ['display_name' => '甲管理员', 'role' => 'admin'],
        ['display_name' => '未设置姓名', 'role' => 'member'],
        ['display_name' => '甲成员', 'role' => 'member'],
    ]], 'Ordinary members see exactly names and roles for their own organization');
    $manager = $expect($list('a', 'owner_a'), 200, 'success', 'Owner contact projection');
    check($manager['members'] === [
        ['display_name' => '甲所有者', 'role' => 'owner', 'account_id' => $ids['owner_a'], 'phone' => '+8613800138000', 'email' => 'owner-a@example.test'],
        ['display_name' => '甲管理员', 'role' => 'admin', 'account_id' => $ids['admin'], 'phone' => '+8613800138001', 'email' => null],
        ['display_name' => '未设置姓名', 'role' => 'member', 'account_id' => $ids['dual'], 'phone' => '+8613800138002', 'email' => null],
        ['display_name' => '甲成员', 'role' => 'member', 'account_id' => $ids['ordinary'], 'phone' => '+8613800138004', 'email' => null],
    ], 'Owner sees verified contact details and null for unverified or unbound email');
    check($expect($list('a', 'admin'), 200, 'success', 'Admin contact projection') === $manager, 'Admin sees the same scoped contacts');
    $expect($list('a', 'platform'), 403, 'token_purpose_mismatch', 'Platform token cannot access member contacts even for the same account');
    $platform = $expect($request('GET', '/saas/platform/me', 'platform'), 200, 'success', 'Platform session works at its own API');
    check(!array_key_exists('phone', $platform) && !array_key_exists('email', $platform), 'Platform profile omits restricted contacts');
    $expect($list('a', null), 401, 'unauthenticated', 'Authentication precedes access');
    $expect($list('a', 'owner_b'), 404, 'not_found', 'Other tenant roster is hidden');
    $expect($remove('a', 'owner_a', 'owner_b'), 404, 'not_found', 'Other tenant target cannot be removed');
    $expect($change('a', 'owner_a', 'owner_b', 'admin'), 404, 'not_found', 'Other tenant target role cannot change');
    $expect($remove('b', 'owner_a', 'dual'), 404, 'not_found', 'Owner of A cannot remove dual account from B');
    $expect($change('a', 'admin', 'dual', 'admin'), 403, 'forbidden', 'Admin cannot appoint admin');
    $expect($change('a', 'owner_a', 'dual', 'owner'), 403, 'forbidden', 'An owner cannot create another owner via role change');
    $expect($change('a', 'owner_a', 'owner_a', 'admin'), 403, 'forbidden', 'Role change cannot demote owner');
    $expect($change('a', 'owner_a', 'dual', ['admin']), 422, 'validation_failed', 'Role input requires a fixed role string');
    $expect($exit('a', 'owner_a'), 409, 'last_owner_required', 'Owner must transfer before exit');
    $expect($remove('a', 'owner_a', 'owner_a'), 409, 'last_owner_required', 'Deletion cannot remove sole owner');
    $expect($remove('a', 'admin', 'owner_a'), 409, 'last_owner_required', 'Admin cannot remove sole owner');
    $expect($remove('a', 'dual', 'ordinary'), 403, 'forbidden', 'Ordinary member cannot remove others');

    $expect($request('PATCH', $path('a') . '/' . $ids['dual'] . '/role?role=owner', 'owner_a', ['role' => 'admin',
        'organization_id' => $ids['b'], 'account_id' => $ids['owner_b']]), 200, 'success',
        'Owner promotes the path member with role from the body only');
    $promoted = $expect($list('a', 'dual'), 200, 'success', 'Old token obtains current admin projection');
    check($promoted['members'][2]['role'] === 'admin' && $promoted['members'][0]['phone'] === '+8613800138000',
        'Promotion takes effect on the next request with the original token');
    $expect($remove('a', 'admin', 'dual'), 403, 'forbidden', 'Admin cannot remove another admin');
    $expect($change('a', 'owner_a', 'dual', 'member'), 200, 'success', 'Owner demotes admin');
    $expect($remove('a', 'dual', 'ordinary'), 403, 'forbidden', 'Immediately deny former administrator with original token');
    check($expect($list('a', 'dual'), 200, 'success', 'Demoted member lists A') === $a, 'Demotion revokes restricted contacts on next request');

    foreach (['archived' => 'organization_archived', 'suspended' => 'organization_suspended',
        'pending' => 'organization_pending', 'inactive' => 'subscription_inactive'] as $org => $code) {
        $expect($list($org, 'owner_a'), 403, $code, "$org blocks organization listing");
        $expect($change($org, 'owner_a', 'dual', 'admin'), 403, $code, "$org blocks role changes");
        $expect($remove($org, 'admin', 'dual'), 403, $code, "$org blocks removal");
        $expect($exit($org, 'dual'), 403, $code, "$org blocks exit");
        $expect($list($org, 'owner_b'), 404, 'not_found', "$org status is hidden from nonmembers");
    }
    $expect($change('expired', 'owner_a', 'dual', 'admin'), 403, 'subscription_expired', 'Expiry blocks role changes');
    $expect($list('expired', 'admin'), 200, 'success', 'Expiry preserves existing read permission');
    check($expect($remove('expired', 'admin', 'dual'), 200, 'success', 'Admin may remove member at expiry') === null, 'Deletion has null data');
    check($expect($exit('expired', 'ordinary'), 200, 'success', 'Member may exit at expiry') === null, 'Exit has null data');
    $expect($list('expired', 'ordinary'), 404, 'not_found', 'Exit takes effect on next request');

    // A failed audit must roll back the scoped membership deletion.
    $sql->exec("CREATE TRIGGER fixture_issue21_audit_failure BEFORE INSERT ON starter_audit_logs FOR EACH ROW "
        . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture audit failure'");
    try {
        $expect($remove('a', 'admin', 'dual'), 503, 'audit_unavailable', 'Audit failure aborts removal');
        check($expect($list('a', 'dual'), 200, 'success', 'Removed membership rolled back') === $a,
            'Member retains access after failed deletion');
    } finally {
        $sql->exec('DROP TRIGGER fixture_issue21_audit_failure');
    }
    $expect($remove('a', 'admin', 'dual'), 200, 'success', 'Admin removes an ordinary member');
    $expect($list('a', 'dual'), 404, 'not_found', 'Removed account loses A on next request with same token');
    $b = $expect($list('b', 'dual'), 200, 'success', 'Global account and B membership survive A removal');
    check($b['members'] === [['display_name' => '未设置姓名', 'role' => 'member'], ['display_name' => '乙所有者', 'role' => 'owner']],
        'Cross-tenant membership retains the original role and projection');
    $expect($remove('a', 'owner_a', 'admin'), 200, 'success', 'Owner can remove an administrator');
    $expect($list('a', 'admin'), 404, 'not_found', 'Removed admin cannot read A with its original token');
    $expect($list('expired', 'admin'), 200, 'success', 'Removed admin retains membership elsewhere');

    $auditQuery = $sql->prepare('SELECT organization_id, action, actor_account_id, target_account_id, metadata '
        . 'FROM starter_audit_logs WHERE id > ? ORDER BY id');
    $auditQuery->execute([$lastAuditId]);
    $audits = $auditQuery->fetchAll(PDO::FETCH_ASSOC);
    check(array_map(static fn (array $row): array => [(int) $row['organization_id'], $row['action'],
        (int) $row['actor_account_id'], (int) $row['target_account_id']], $audits) === [
        [$ids['a'], 'member_role_changed', $ids['owner_a'], $ids['dual']],
        [$ids['a'], 'member_role_changed', $ids['owner_a'], $ids['dual']],
        [$ids['expired'], 'member_removed', $ids['admin'], $ids['dual']],
        [$ids['expired'], 'member_exited', $ids['ordinary'], $ids['ordinary']],
        [$ids['a'], 'member_removed', $ids['admin'], $ids['dual']],
        [$ids['a'], 'member_removed', $ids['owner_a'], $ids['admin']],
    ], 'Successful governance audits carry the correct tenant, actor and target');
    check(json_decode($audits[0]['metadata'], true) == ['previous_role' => 'member', 'new_role' => 'admin', 'result' => 'success'],
        'Role audit contains only the role transition and result');
    foreach ($audits as $audit) {
        check(!str_contains($audit['metadata'], '+86') && !str_contains($audit['metadata'], '@'), 'Audit excludes restricted contact details');
    }
}

/** @param array<string, int> $ids */
function checkIssue21Invalidation(PDO $sql, array $ids, int $now): void
{
    $sql->exec('CREATE TABLE fixture_issue21_transfers (id INT PRIMARY KEY, organization_id INT NOT NULL, '
        . 'recipient_account_id BIGINT NOT NULL, invalidated_at BIGINT NULL) ENGINE=InnoDB');
    $sql->prepare('INSERT INTO fixture_issue21_transfers VALUES (1, ?, ?, NULL), (2, ?, ?, NULL), (3, ?, ?, NULL)')
        ->execute([$ids['b'], $ids['dual'], $ids['a'], $ids['dual'], $ids['b'], $ids['owner_b']]);
    $connection = new MySqlConnection($sql);
    $organizations = new IlluminateOrganizationRepository($connection);
    $repository = new IlluminateMembershipRepository($connection, new class ($connection) implements MembershipInvalidatorInterface {
        public function __construct(private readonly MySqlConnection $connection)
        {
        }

        public function invalidatePending(int $organizationId, int $accountId, int $now): void
        {
            $this->connection->table('fixture_issue21_transfers')->where('organization_id', $organizationId)
                ->where('recipient_account_id', $accountId)->whereNull('invalidated_at')->update(['invalidated_at' => $now]);
        }
    });
    $remove = static function () use ($repository, $organizations, $ids, $now): void {
        check($organizations->find($ids['b'], lock: true) !== null, 'Organization lock acquired');
        check($repository->findRole($ids['b'], $ids['dual'], lock: true)?->value === 'member', 'Target membership lock acquired');
        $repository->remove($ids['b'], $ids['dual'], $now);
    };
    $invalidationTimes = static fn (): array => $sql->query('SELECT invalidated_at FROM fixture_issue21_transfers ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $rollback = new RuntimeException('fixture rejects the transaction after invalidation');
    try {
        $organizations->transaction(static function () use ($remove, $invalidationTimes, $now, $rollback): void {
            $remove();
            check($invalidationTimes() === [$now, null, null], 'Only the scoped pending transfer is invalidated');
            throw $rollback;
        });
        check(false, 'Expected invalidation rollback');
    } catch (RuntimeException $exception) {
        check($exception === $rollback, $exception->getMessage());
    }
    check($invalidationTimes() === [null, null, null], 'Rollback restores the pending transfer with the relationship');
    $organizations->transaction($remove);
    $sql->prepare("INSERT INTO starter_memberships (organization_id, account_id, role) VALUES (?, ?, 'member')")
        ->execute([$ids['b'], $ids['dual']]);
    check($invalidationTimes() === [$now, null, null], 'Rejoining does not restore the old pending transfer');
}

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/d2-fixtures.php';

$fixture = new Fixtures();
try {
    $qa = prepareD2Host($fixture, 'qa_members');
    $sql = $fixture->database('qa_members', create: false);
    $now = SMS_TIME;
    $ids = seedIssue21($sql, $now);
    $qa->clock($now - 120);
    $qa->withHost('membership', static function (int $port) use ($qa, $sql, $ids, $now): void {
        $login = static function (string $phone, string $purpose) use ($qa, $port): string {
            return smsToken(smsLogin($port, $qa->issue($port, $phone), phone: $phone, purpose: $purpose));
        };
        $tokens = ['owner_a' => $login('13800138000', 'organization')];
        $qa->clock($now);
        foreach (['owner_b' => '13800138003', 'admin' => '13800138001', 'dual' => '13800138002', 'ordinary' => '13800138004'] as $who => $phone) {
            $tokens[$who] = $login($phone, 'organization');
        }
        $tokens['platform'] = $login('13800138000', 'platform');
        $request = static fn (string $method, string $path, ?string $who = null, ?array $body = null): array =>
            smsRequest($port, $method, $path, $body, $who === null ? [] : sessionBearer($tokens[$who]));
        checkIssue21($sql, $ids, $request);
        checkIssue21Invalidation($sql, $ids, $now);
    }, ['WEBMAN_STARTER_HTTP_WORKERS' => '1']);
    echo "Issue #21 membership HTTP and invalidation passed through the installed Webman starter routes\n";
} finally {
    $fixture->close();
}
