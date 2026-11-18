<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\membership;

use Z0x\WebmanStarter\saas\organizationaccess\OrganizationMembershipRepositoryInterface;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;

interface MembershipRepositoryInterface extends OrganizationMembershipRepositoryInterface
{
    /**
     * @return list<array{display_name: string, role: string, account_id?: int, phone?: string, email?: ?string}>
     */
    public function listMembers(int $organizationId, bool $includeContacts): array;

    /** Call with the organization's transaction and target membership lock held. */
    public function updateRole(int $organizationId, int $accountId, OrganizationRole $role): void;

    /** Call with the organization's transaction and target membership lock held. Invalidates dependent pending transfers. */
    public function remove(int $organizationId, int $accountId, int $now): void;
}
