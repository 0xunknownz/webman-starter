<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\service;

use Z0x\WebmanStarter\saas\contract\PlatformAdminRepositoryInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class InitializePlatformAdminService
{
    public function __construct(private readonly PlatformAdminRepositoryInterface $repository)
    {
    }

    public function initialize(MainlandMobileNumber $phone, string $actorReference): void
    {
        $this->repository->withGuard(function () use ($phone, $actorReference): void {
            if ($this->repository->hasActiveAdministrator()) {
                throw new BusinessException(
                    'An active platform administrator already exists.',
                    'platform_admin_already_initialized',
                    409
                );
            }

            $now = gmdate('Y-m-d H:i:s');
            $account = $this->repository->findOrCreateAccount($phone, $now);
            $this->repository->grantAdministrator($account['id'], $now);
            $this->repository->appendAudit(
                'platform_admin_initialized',
                null,
                $account['id'],
                [
                    'actor_ref' => $actorReference,
                    'phone_masked' => $phone->masked(),
                    'account_created' => $account['created'],
                    'source' => 'cli',
                    'result' => 'success',
                ],
                $now
            );
        });
    }
}
