<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitation;

use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;

interface InvitationRepositoryInterface
{
    public function find(int $organizationId, int $id, bool $lock = false): ?Invitation;

    /** Looks up a selector already verified by InvitationLink. */
    public function findBySelector(string $selector): ?Invitation;

    public function latestOwner(int $organizationId, bool $lock = false): ?Invitation;

    /** These mutations require the same transaction and organization row lock as the lifecycle checks. */
    public function insert(int $organizationId, MainlandMobileNumber $phone, OrganizationRole $role, int $expiresAt): Invitation;

    public function revoke(Invitation $invitation): void;

    /** Hint to future management operations that this exact row has already been replaced. */
    public function markReplaced(Invitation $invitation, int $now): void;

    public function revokePending(int $organizationId): void;

    /** Accept only after rechecking the active invite, live proof, eligibility and seat under lock. */
    public function consume(Invitation $invitation, int $accountId): void;
}
