<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\ownership;

use Z0x\WebmanStarter\saas\organizationaccess\OrganizationMembershipRepositoryInterface;

interface OwnershipTransferRepositoryInterface extends OrganizationMembershipRepositoryInterface
{
    /** Writes and locking reads require the caller's transaction and the organization lock. */
    public function create(int $organizationId, int $proposerId, int $recipientId, int $now): OwnershipTransfer;

    public function find(int $organizationId, int $transferId, bool $lock = false): ?OwnershipTransfer;

    public function hasExpiredPending(int $organizationId, int $recipientId, int $now): bool;

    /** @return list<array{transfer_id:int, proposer_display_name:string, expires_at:string}> */
    public function inbox(int $organizationId, int $recipientId, int $now): array;

    /** @return list<OwnershipTransfer> */
    public function lockExpired(int $organizationId, int $now, ?int $recipientId = null): array;

    public function resolve(OwnershipTransfer $transfer, string $status, int $now): void;

    public function invalidatePending(int $organizationId, int $now, ?int $recipientId = null): void;

    /** Demote the current owner before promoting the recipient, under the organization lock. */
    public function swapOwner(int $organizationId, int $currentOwnerId, int $recipientId): void;
}
