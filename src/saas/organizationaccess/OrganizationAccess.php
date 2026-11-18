<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organizationaccess;

use Z0x\WebmanStarter\saas\organization\Organization;

/** Authorized organization and actor for the current request or locked mutation. */
final class OrganizationAccess
{
    public function __construct(
        public readonly Organization $organization,
        public readonly int $accountId,
        public readonly OrganizationRole $role,
    ) {
    }
}
