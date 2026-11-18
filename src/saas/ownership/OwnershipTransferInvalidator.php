<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\ownership;

use Z0x\WebmanStarter\saas\contract\OrganizationAuditInterface;
use Z0x\WebmanStarter\saas\membership\MembershipInvalidatorInterface;

final class OwnershipTransferInvalidator implements MembershipInvalidatorInterface
{
    public function __construct(
        private readonly OwnershipTransferRepositoryInterface $transfers,
        private readonly OrganizationAuditInterface $audit,
    ) {
    }

    public function invalidatePending(int $organizationId, int $accountId, int $now): void
    {
        $this->expirePending($organizationId, $now, $accountId);
        $this->transfers->invalidatePending($organizationId, $now, $accountId);
    }

    /** Expiry is materialized in the caller's transaction under the organization lock. */
    public function expirePending(int $organizationId, int $now, ?int $recipientId = null): void
    {
        foreach ($this->transfers->lockExpired($organizationId, $now, $recipientId) as $transfer) {
            $this->transfers->resolve($transfer, 'expired', $now);
            $this->audit->append($organizationId, 'ownership_transfer_expired', null, $transfer->recipientAccountId, [
                'transfer_id' => $transfer->id,
                'proposer_account_id' => $transfer->proposerAccountId,
                'expires_at' => $transfer->expiresAt,
                'result' => 'expired',
            ], $now);
        }
    }
}
