<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\membership;

interface MembershipInvalidatorInterface
{
    /**
     * Permanently invalidate pending ownership transfers for this recipient in this organization.
     * Runs after the scoped membership delete and before commit, while the organization lock is held.
     * Implementations must use the same database transaction and serialize transfer writes on the same
     * organization lock. A new relationship with the same key must never restore these requests.
     */
    public function invalidatePending(int $organizationId, int $accountId, int $now): void;
}
