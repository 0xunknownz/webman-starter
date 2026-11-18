<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organizationaccess;

use Illuminate\Database\ConnectionInterface;
use LogicException;

final class IlluminateOrganizationMembershipRepository implements OrganizationMembershipRepositoryInterface
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function findRole(int $organizationId, int $accountId, bool $lock = false): ?OrganizationRole
    {
        $query = $this->connection->table('starter_memberships')->useWritePdo()
            ->where('organization_id', $organizationId)->where('account_id', $accountId);
        if ($lock) {
            if ($this->connection->transactionLevel() === 0) {
                throw new LogicException('Membership locking requires an organization transaction.');
            }
            $query->lockForUpdate();
        }
        $role = $query->value('role');

        return $role === null ? null : OrganizationRole::from($role);
    }
}
