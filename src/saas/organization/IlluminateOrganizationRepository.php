<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organization;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use LogicException;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class IlluminateOrganizationRepository implements OrganizationRepositoryInterface
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function transaction(Closure $operation): mixed
    {
        // Callbacks must contain only transactional database operations and side-effect-free checks.
        return $this->connection->transaction($operation, 5);
    }

    public function find(int $organizationId, bool $lock = false): ?Organization
    {
        $query = $this->connection->table('starter_organizations')->useWritePdo()->where('id', $organizationId);
        if ($lock) {
            $this->requireTransaction();
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            return null;
        }
        $utc = new DateTimeZone('UTC');

        return new Organization((int) $row->id, $row->name, $row->activated_at !== null,
            $row->suspended_at !== null, $row->archived_at !== null, new SubscriptionWindow(
                new DateTimeImmutable($row->subscription_starts_at, $utc),
                new DateTimeImmutable($row->subscription_expires_at, $utc),
            ), $row->package_id === null ? null : (int) $row->package_id, $row->package_name,
            $row->seat_limit === null ? null : (int) $row->seat_limit);
    }

    public function create(string $name, int $packageId, SubscriptionWindow $subscription): Organization
    {
        $this->requireTransaction();
        $package = $this->connection->table('starter_packages')->where('id', $packageId)->lockForUpdate()->first();
        if ($package === null) {
            throw BusinessException::notFound();
        }
        if ($package->retired_at !== null) {
            throw new BusinessException('套餐已停用', 'package_retired', 409);
        }
        $name = self::validatedName($name, 100, 'organization_name_invalid');
        $packageName = self::validatedName($package->name, 50, 'package_invalid');
        $seatLimit = (int) $package->seat_limit;
        if ($seatLimit < 1 || $seatLimit > 100_000) {
            throw new BusinessException('套餐席位无效', 'package_invalid', 422);
        }
        $utc = new DateTimeZone('UTC');
        $id = $this->connection->table('starter_organizations')->insertGetId([
            'name' => $name,
            'package_id' => $packageId,
            'package_name' => $packageName,
            'seat_limit' => $seatLimit,
            'subscription_starts_at' => $subscription->startsAt->setTimezone($utc)->format('Y-m-d H:i:s'),
            'subscription_expires_at' => $subscription->expiresAt->setTimezone($utc)->format('Y-m-d H:i:s'),
        ]);

        return new Organization((int) $id, $name, false, false, false, $subscription, $packageId, $packageName, $seatLimit);
    }

    public static function validatedName(string $name, int $limit, string $code): string
    {
        // Reject line separators and controls before trimming the surrounding spaces.
        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $name) !== 0) {
            throw new BusinessException('名称无效', $code, 422);
        }
        $trimmed = preg_replace('/\A\p{Zs}+|\p{Zs}+\z/u', '', $name);
        if ($trimmed === null || preg_match('/\A.{1,' . $limit . '}\z/u', $trimmed) !== 1) {
            throw new BusinessException('名称无效', $code, 422);
        }

        return $trimmed;
    }

    public function lockAccountByPhone(MainlandMobileNumber $phone): ?int
    {
        $this->requireTransaction();
        $row = $this->connection->table('starter_accounts')->where('phone', $phone->e164)
            ->lockForUpdate()->first(['id']);

        return $row === null ? null : (int) $row->id;
    }

    public function seatOccupancy(int $organizationId, int $now): int
    {
        $members = $this->connection->table('starter_memberships')->useWritePdo()->where('organization_id', $organizationId);
        $invites = $this->pendingInvitations($organizationId, $now)->where('reserved', true);
        if ($this->connection->transactionLevel() > 0) {
            // Current reads are required even if a prior read in this transaction established an MVCC snapshot.
            return (int) $members->lockForUpdate()->count() + (int) $invites->lockForUpdate()->count();
        }

        // One read snapshot avoids transient double counting when a reservation becomes a membership.
        $row = $this->connection->selectOne(
            'SELECT (SELECT COUNT(*) FROM starter_memberships WHERE organization_id = ?) + '
                . '(SELECT COUNT(*) FROM starter_invitations WHERE organization_id = ? '
                . "AND status = 'pending' AND expires_at > ? AND reserved = 1) AS occupied",
            [$organizationId, $organizationId, gmdate('Y-m-d H:i:s', $now)],
            false,
        );

        return (int) $row->occupied;
    }

    public function assertInvitationCapacity(Organization $organization, MainlandMobileNumber $phone, int $now, ?int $replacing = null): void
    {
        $this->requireTransaction();
        $member = $this->connection->table('starter_memberships as membership')
            ->join('starter_accounts as account', 'account.id', '=', 'membership.account_id')
            ->where('membership.organization_id', $organization->id)->where('account.phone', $phone->e164)
            ->lockForUpdate()->first(['membership.account_id']);
        if ($member !== null) {
            throw new BusinessException('目标账号已是组织成员', 'member_already_exists', 409);
        }
        $active = $this->pendingInvitations($organization->id, $now);
        $duplicate = (clone $active)->where('phone', $phone->e164);
        if ($replacing !== null) {
            $duplicate->where('id', '<>', $replacing);
        }
        if ($duplicate->lockForUpdate()->first(['id']) !== null) {
            throw new BusinessException('同组织已有有效邀请', 'invitation_already_exists', 409);
        }
        $replacesReservation = $replacing !== null && $active->where('id', $replacing)->where('reserved', true)
            ->lockForUpdate()->first(['id']) !== null;
        if ($organization->seatLimit === null
            || $this->seatOccupancy($organization->id, $now) + ($replacesReservation ? 0 : 1) > $organization->seatLimit) {
            throw new BusinessException('组织席位不足', 'seat_limit_exceeded', 409);
        }
    }

    private function pendingInvitations(int $organizationId, int $now): Builder
    {
        return $this->connection->table('starter_invitations')->useWritePdo()->where('organization_id', $organizationId)
            ->where('status', 'pending')->where('expires_at', '>', gmdate('Y-m-d H:i:s', $now));
    }

    private function requireTransaction(): void
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException('An organization transaction is required.');
        }
    }
}
