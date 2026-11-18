<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\ownership;

use Closure;
use Z0x\WebmanStarter\saas\contract\OrganizationAuditInterface;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccess;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccessService;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationOperation;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class OwnershipTransferService
{
    private readonly OwnershipTransferInvalidator $invalidator;

    /** @param Closure(): int $clock */
    public function __construct(
        private readonly OrganizationAccessService $access,
        private readonly SessionService $sessions,
        private readonly OwnershipTransferRepositoryInterface $transfers,
        private readonly OrganizationAuditInterface $audit,
        private readonly Closure $clock,
    ) {
        $this->invalidator = new OwnershipTransferInvalidator($transfers, $audit);
    }

    /** @return array{transfer_id:int, expires_at:string} */
    public function start(SessionIdentity $identity, int $organizationId, mixed $recipientAccountId): array
    {
        return $this->write($identity, $organizationId, OrganizationOperation::OwnershipTransferStart,
            function (OrganizationAccess $context) use ($recipientAccountId): array {
                if ((!is_int($recipientAccountId) && !is_string($recipientAccountId))
                    || ($recipient = filter_var($recipientAccountId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false) {
                    throw BusinessException::validation(['recipient_account_id' => ['接收人账号 ID 无效']]);
                }
                $organizationId = $context->organization->id;
                $role = $this->transfers->findRole($organizationId, $recipient, lock: true)
                    ?? throw BusinessException::notFound();
                if ($recipient === $context->accountId || $role === OrganizationRole::Owner) {
                    throw BusinessException::forbidden();
                }
                $now = ($this->clock)();
                $this->invalidator->expirePending($organizationId, $now);
                $transfer = $this->transfers->create($organizationId, $context->accountId, $recipient, $now);
                $this->record($transfer, 'started', $context->accountId, $now);

                return ['transfer_id' => $transfer->id, 'expires_at' => gmdate(DATE_ATOM, $transfer->expiresAt)];
            });
    }

    /** @return list<array{transfer_id:int, organization_id:int, organization_name:string, proposer_display_name:string, expires_at:string}> */
    public function pending(SessionIdentity $identity, int $organizationId): array
    {
        $operation = OrganizationOperation::OwnershipTransferAccept;
        $now = ($this->clock)();
        $organization = $this->access->require($identity, $organizationId, $operation, $now)->organization;
        $recipient = $identity->accountId;
        if ($this->transfers->hasExpiredPending($organization->id, $recipient, $now)) {
            $this->write($identity, $organization->id, $operation,
                function (OrganizationAccess $context): void {
                    $this->invalidator->expirePending($context->organization->id, ($this->clock)(), $context->accountId);
                });
            $now = ($this->clock)();
            $organization = $this->access->require($identity, $organizationId, $operation, $now)->organization;
        }

        $result = [];
        foreach ($this->transfers->inbox($organization->id, $recipient, $now) as $transfer) {
            $result[] = $transfer + ['organization_id' => $organization->id, 'organization_name' => $organization->name];
        }

        return $result;
    }

    public function revoke(SessionIdentity $identity, int $organizationId, int $transferId): void
    {
        $revoked = $this->write($identity, $organizationId, OrganizationOperation::OwnershipTransferRevoke,
            function (OrganizationAccess $context) use ($transferId): bool {
                $organizationId = $context->organization->id;
                $transfer = $this->transfers->find($organizationId, $transferId, lock: true);
                if ($transfer === null || $transfer->proposerAccountId !== $context->accountId) {
                    throw BusinessException::notFound();
                }
                $now = ($this->clock)();
                $this->invalidator->expirePending($organizationId, $now);
                if (!$transfer->isPendingAt($now)) {
                    return false;
                }
                $this->transfers->resolve($transfer, 'revoked', $now);
                $this->record($transfer, 'revoked', $context->accountId, $now);

                return true;
            });
        if (!$revoked) {
            throw self::invalidTransfer();
        }
    }

    public function accept(SessionIdentity $identity, int $organizationId, int $transferId): void
    {
        try {
            $accepted = $this->write($identity, $organizationId, OrganizationOperation::OwnershipTransferAccept,
                function (OrganizationAccess $context) use ($transferId): bool {
                    $organizationId = $context->organization->id;
                    $transfer = $this->transfers->find($organizationId, $transferId, lock: true);
                    if ($transfer === null || $transfer->recipientAccountId !== $context->accountId) {
                        throw BusinessException::notFound();
                    }
                    $now = ($this->clock)();
                    $this->invalidator->expirePending($organizationId, $now);
                    if (!$transfer->isPendingAt($now) || $context->role === OrganizationRole::Owner
                        || $this->transfers->findRole($organizationId, $transfer->proposerAccountId, lock: true) !== OrganizationRole::Owner) {
                        return false;
                    }
                    $this->transfers->swapOwner($organizationId, $transfer->proposerAccountId, $context->accountId);
                    $this->transfers->resolve($transfer, 'completed', $now);
                    $this->transfers->invalidatePending($organizationId, $now);
                    $this->record($transfer, 'completed', $context->accountId, $now);

                    return true;
                });
        } catch (BusinessException $exception) {
            if ($exception->code() === 'not_found' && $identity->accountId
                === $this->transfers->find($organizationId, $transferId)?->recipientAccountId) {
                // FR-08.02 reports a lost relationship to the original recipient, without exposing guessed IDs.
                throw self::invalidTransfer();
            }
            throw $exception;
        }
        // Throw after commit so an expired request and its audit remain recorded after a rejected acceptance.
        if (!$accepted) {
            throw self::invalidTransfer();
        }
    }

    /** @template T @param Closure(OrganizationAccess): T $mutation @return T */
    private function write(SessionIdentity $identity, int $organizationId, OrganizationOperation $operation, Closure $mutation): mixed
    {
        return $this->access->write($this->sessions, $identity, $organizationId, $operation, ($this->clock)(), $mutation,
            clock: $this->clock);
    }

    private function record(OwnershipTransfer $transfer, string $result, int $actorAccountId, int $now): void
    {
        $this->audit->append($transfer->organizationId, 'ownership_transfer_' . $result, $actorAccountId,
            $transfer->recipientAccountId, [
                'transfer_id' => $transfer->id,
                'proposer_account_id' => $transfer->proposerAccountId,
                'result' => $result,
            ], $now);
    }

    private static function invalidTransfer(): BusinessException
    {
        return new BusinessException('所有权转移请求已失效', 'ownership_transfer_invalid', 409);
    }
}
