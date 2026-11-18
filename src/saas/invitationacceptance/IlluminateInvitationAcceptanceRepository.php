<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitationacceptance;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\invitation\Invitation;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;

final class IlluminateInvitationAcceptanceRepository implements InvitationAcceptanceRepositoryInterface
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function createAccount(MainlandMobileNumber $phone, int $now): ?int
    {
        $date = gmdate('Y-m-d H:i:s', $now);
        try {
            return (int) $this->connection->table('starter_accounts')->insertGetId([
                'phone' => $phone->e164,
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        } catch (QueryException $exception) {
            // A missing phone need not have a gap lock under READ COMMITTED. The unique index still wins.
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $exception;
        }
    }

    public function hasMember(int $organizationId, int $accountId): bool
    {
        return $this->connection->table('starter_memberships')->where('organization_id', $organizationId)
            ->where('account_id', $accountId)->lockForUpdate()->first(['account_id']) !== null;
    }

    public function hasOwner(int $organizationId): bool
    {
        return $this->connection->table('starter_memberships')->where('organization_id', $organizationId)
            ->where('role', OrganizationRole::Owner->value)->lockForUpdate()->first(['account_id']) !== null;
    }

    public function addMember(int $organizationId, int $accountId, OrganizationRole $role): void
    {
        $this->connection->table('starter_memberships')->insert([
            'organization_id' => $organizationId,
            'account_id' => $accountId,
            'role' => $role->value,
        ]);
    }

    public function activate(int $organizationId, int $now): void
    {
        $changed = $this->connection->table('starter_organizations')->where('id', $organizationId)
            ->whereNull('activated_at')->update(['activated_at' => gmdate('Y-m-d H:i:s', $now)]);
        if ($changed !== 1) {
            throw Invitation::invalid();
        }
    }
}
