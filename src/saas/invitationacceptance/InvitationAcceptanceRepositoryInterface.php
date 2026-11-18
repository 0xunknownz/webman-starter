<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitationacceptance;

use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;

/** Mutations and current reads use the same transaction as the locked organization and invitation. */
interface InvitationAcceptanceRepositoryInterface
{
    /** Returns null on a competing insert. The caller must restart with the phone lock. */
    public function createAccount(MainlandMobileNumber $phone, int $now): ?int;

    public function hasMember(int $organizationId, int $accountId): bool;

    public function hasOwner(int $organizationId): bool;

    public function addMember(int $organizationId, int $accountId, OrganizationRole $role): void;

    public function activate(int $organizationId, int $now): void;
}
