<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organizationaccess;

interface OrganizationMembershipRepositoryInterface
{
    /** A locking read must use the active transaction after the organization row lock. */
    public function findRole(int $organizationId, int $accountId, bool $lock = false): ?OrganizationRole;
}
