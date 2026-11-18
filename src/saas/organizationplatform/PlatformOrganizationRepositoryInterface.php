<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organizationplatform;

use Closure;
use Z0x\WebmanStarter\saas\organization\Organization;
use Z0x\WebmanStarter\saas\organization\SubscriptionWindow;

interface PlatformOrganizationRepositoryInterface
{
    public function transaction(Closure $operation): mixed;

    /** Lock the organization row in the current transaction. */
    public function lock(int $organizationId): Organization;

    public function create(string $name, int $packageId, SubscriptionWindow $subscription): Organization;

    /** @return string Normalized organization name. */
    public function rename(Organization $organization, string $name): string;

    public function setSuspended(int $organizationId, ?int $suspendedAt): void;

    public function setArchived(int $organizationId, ?int $archivedAt): void;
}
