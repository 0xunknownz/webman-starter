<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\platform;

use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class ManagePlatformAdminsService
{
    public function __construct(private readonly PlatformAdminMutationRepositoryInterface $repository)
    {
    }

    public function grant(SessionIdentity $identity, string $phone): int
    {
        $this->requirePlatformPurpose($identity);
        $number = MainlandMobileNumber::fromInput($phone);

        return $this->repository->withGuard(function () use ($identity, $number): int {
            $now = gmdate('Y-m-d H:i:s');
            $actorId = $identity->accountId;
            $accounts = $this->repository->lockGrantAccounts($actorId, $number, $now);
            $targetId = $accounts['target_id'];
            $admins = $this->repository->lockAdministrators($actorId, $targetId);
            $this->requireCurrentActor($identity, $accounts['actor_epoch'], $admins[$actorId] ?? null);

            if (($admins[$targetId]['status'] ?? null) === 'active') {
                throw new BusinessException('The account is already a platform administrator.', 'platform_admin_already_granted', 409);
            }

            $this->repository->grantAdministrator($targetId, $now);
            $this->repository->appendAudit('platform_admin_granted', $actorId, $targetId, [
                'phone_masked' => $number->masked(),
                'account_created' => $accounts['created'],
                'source' => 'api',
                'result' => 'success',
            ], $now);

            return $targetId;
        });
    }

    public function revoke(SessionIdentity $identity, int $accountId): void
    {
        $this->requirePlatformPurpose($identity);
        $revoked = $this->repository->withGuard(function () use ($identity, $accountId): bool {
            $now = gmdate('Y-m-d H:i:s');
            $actorId = $identity->accountId;
            $actorEpoch = $this->repository->lockAccountEpoch($actorId, $accountId);
            $admins = $this->repository->lockAdministrators($actorId, $accountId);
            $this->requireCurrentActor($identity, $actorEpoch, $admins[$actorId] ?? null);

            if (($admins[$accountId]['status'] ?? null) !== 'active') {
                throw BusinessException::notFound('Active platform administrator not found.');
            }
            if (!$this->repository->hasOtherActiveAdministrator($accountId)) {
                $this->repository->appendAudit('platform_admin_revoke_denied', $actorId, $accountId, [
                    'source' => 'api',
                    'result' => 'denied',
                    'reason' => 'last_platform_admin_required',
                ], $now);

                return false;
            }

            $this->repository->revokeAdministrator($accountId, $now);
            $this->repository->appendAudit('platform_admin_revoked', $actorId, $accountId, [
                'source' => 'api',
                'result' => 'success',
            ], $now);

            return true;
        });

        if (!$revoked) {
            // The denial is reported only after the audit transaction has committed.
            throw new BusinessException('At least one active platform administrator is required.', 'last_platform_admin_required', 409);
        }
    }

    private function requirePlatformPurpose(SessionIdentity $identity): void
    {
        if ($identity->purpose !== SessionService::PURPOSE_PLATFORM) {
            throw new BusinessException('认证令牌用途不匹配', 'token_purpose_mismatch', 403);
        }
    }

    /** @param array{status: string, epoch: string}|null $admin */
    private function requireCurrentActor(SessionIdentity $identity, ?string $accountEpoch, ?array $admin): void
    {
        if ($identity->accountEpoch !== $accountEpoch) {
            throw BusinessException::tokenInvalid();
        }
        if (($admin['status'] ?? null) !== 'active') {
            throw BusinessException::forbidden();
        }
        if ($identity->platformEpoch !== $admin['epoch']) {
            throw BusinessException::tokenInvalid();
        }
    }
}
