<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\subscription;

use Closure;
use Z0x\WebmanStarter\saas\contract\OrganizationAuditInterface;
use Z0x\WebmanStarter\saas\organization\Organization;
use Z0x\WebmanStarter\saas\organization\SubscriptionWindow;
use Z0x\WebmanStarter\saas\package\PackageRepositoryInterface;
use Z0x\WebmanStarter\saas\package\PackageTemplate;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class ManageSubscriptionsService
{
    /** @param Closure():int $clock */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $repository,
        private readonly OrganizationAuditInterface $audit,
        private readonly SessionService $sessions,
        private readonly Closure $clock,
        private readonly PackageRepositoryInterface $packages,
    ) {
    }

    public function updateWindow(
        SessionIdentity $actor,
        int $organizationId,
        mixed $startsAt,
        mixed $expiresAt,
    ): SubscriptionWindow {
        return $this->withOrganization(
            $actor,
            $organizationId,
            function (Organization $organization, int $now) use ($actor, $startsAt, $expiresAt): SubscriptionWindow {
                $window = SubscriptionWindow::fromInput($startsAt, $expiresAt);
                $previous = $organization->subscription;
                if (!$previous->isSameAs($window)) {
                    $this->repository->updateWindow($organization->id, $window);
                    $this->audit->append($organization->id, 'subscription_window_updated', $actor->accountId, null, [
                        'old_starts_at' => $previous->startsAt->format(DATE_ATOM),
                        'old_expires_at' => $previous->expiresAt->format(DATE_ATOM),
                        'new_starts_at' => $window->startsAt->format(DATE_ATOM),
                        'new_expires_at' => $window->expiresAt->format(DATE_ATOM),
                        'result' => 'success',
                    ], $now);
                }

                return $window;
            },
        );
    }

    public function switchPackage(SessionIdentity $actor, int $organizationId, mixed $packageId): PackageTemplate
    {
        return $this->withOrganization($actor, $organizationId, function (Organization $organization) use ($actor, $packageId): PackageTemplate {
            if (!is_int($packageId) || $packageId < 1) {
                throw BusinessException::validation(['package_id' => ['请选择套餐']]);
            }
            $package = $this->packages->lockAvailable($packageId);
            $now = $this->sessions->liveTime($actor, $this->clock);
            // Invitation and membership writes share the organization lock held by withOrganization().
            if ($this->repository->seatOccupancy($organization->id, $now) > $package->seatLimit) {
                throw new BusinessException('组织席位不足', 'seat_limit_exceeded', 409);
            }
            if ($organization->packageId !== $package->id || $organization->packageName !== $package->name
                || $organization->seatLimit !== $package->seatLimit) {
                $this->repository->updatePackage($organization->id, $package);
                $this->audit->append($organization->id, 'subscription_package_changed', $actor->accountId, null, [
                    'old_package_id' => $organization->packageId,
                    'new_package_id' => $package->id,
                    'old_seat_limit' => $organization->seatLimit,
                    'new_seat_limit' => $package->seatLimit,
                    'result' => 'success',
                ], $now);
            }

            return $package;
        });
    }

    /**
     * @template T
     * @param Closure(Organization, int):T $operation
     * @return T
     */
    private function withOrganization(SessionIdentity $actor, int $organizationId, Closure $operation): mixed
    {
        return $this->sessions->withPlatformWrite(
            $actor,
            $this->clock,
            $this->repository->transaction(...),
            function () use ($actor, $organizationId, $operation): mixed {
                $organization = $this->repository->lock($organizationId);

                return $operation($organization, $this->sessions->liveTime($actor, $this->clock));
            },
        );
    }
}
