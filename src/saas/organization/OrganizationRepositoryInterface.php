<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organization;

use Closure;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;

interface OrganizationRepositoryInterface
{
    public function transaction(Closure $operation): mixed;

    public function find(int $organizationId, bool $lock = false): ?Organization;

    public function create(string $name, int $packageId, SubscriptionWindow $subscription): Organization;

    /** Call before locking an organization for operations that add an invitation or accept one. */
    public function lockAccountByPhone(MainlandMobileNumber $phone): ?int;

    public function seatOccupancy(int $organizationId, int $now): int;

    /** Call under the organization lock. $replacing is the specific invitation ID selected for replacement. */
    public function assertInvitationCapacity(Organization $organization, MainlandMobileNumber $phone, int $now, ?int $replacing = null): void;
}
