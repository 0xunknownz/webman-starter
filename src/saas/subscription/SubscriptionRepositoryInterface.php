<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\subscription;

use Closure;
use Z0x\WebmanStarter\saas\organization\Organization;
use Z0x\WebmanStarter\saas\organization\SubscriptionWindow;
use Z0x\WebmanStarter\saas\package\PackageTemplate;

interface SubscriptionRepositoryInterface
{
    public function transaction(Closure $operation): mixed;

    public function lock(int $organizationId): Organization;

    /** Update a changed window under the organization lock. */
    public function updateWindow(int $organizationId, SubscriptionWindow $window): void;

    /** Count current memberships and live reservations under the organization lock. */
    public function seatOccupancy(int $organizationId, int $now): int;

    /** Update the package snapshot under the organization lock. */
    public function updatePackage(int $organizationId, PackageTemplate $package): void;
}
