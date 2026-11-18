<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organizationaccess;

use Closure;
use InvalidArgumentException;
use Z0x\WebmanStarter\saas\organization\Organization;
use Z0x\WebmanStarter\saas\organization\OrganizationRepositoryInterface;
use Z0x\WebmanStarter\saas\organization\SubscriptionWindow;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class OrganizationAccessService
{
    /** @param array<string, list<OrganizationRole>> $businessPermissions Keys declare permissions. Owners have every declared key. */
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly OrganizationMembershipRepositoryInterface $memberships,
        private readonly array $businessPermissions = [],
    ) {
        foreach ($businessPermissions as $permission => $roles) {
            if (!is_string($permission) || trim($permission) === '' || !is_array($roles) || !array_is_list($roles)) {
                throw new InvalidArgumentException('Business permissions must map names to role lists.');
            }
            foreach ($roles as $role) {
                if (!$role instanceof OrganizationRole) {
                    throw new InvalidArgumentException('Business permissions must use the fixed organization roles.');
                }
            }
        }
    }

    /**
     * Pass the authenticated identity for this request and an explicit organization ID.
     * Scope every resource query to the returned organization ID. Identify an absent or foreign resource as not_found.
     * Governance operations still require their own target-specific rules (such as only removing an ordinary member).
     */
    public function require(
        SessionIdentity $identity,
        int $organizationId,
        OrganizationOperation $operation,
        int $now,
        ?string $permission = null,
    ): OrganizationAccess {
        $this->requireOrganizationIdentity($identity);

        [$organization, $role] = $this->loadMembership($identity, $organizationId, lock: false);

        return $this->authorizeMembership($identity, $organization, $role, $operation, $now, $permission);
    }

    /**
     * Use the same transaction-capable connection for the session, tenant, membership and mutation repositories.
     * The optional pre-lock callback follows an unlocked authorization check and may only take target account/phone
     * locks, before locking the organization. Both callbacks can run again on a database retry. Keep external side
     * effects outside this method. Lock/current-read mutable rows in the mutation before enforcing its invariants.
     *
     * @template T
     * @param Closure(OrganizationAccess): T $mutation
     * @param (Closure(OrganizationAccess): mixed)|null $beforeOrganizationLock
     * @param (Closure(): int)|null $clock Resamples time after row locks. Without it, use the supplied request time.
     * @return T
     */
    public function write(
        SessionService $sessions,
        SessionIdentity $identity,
        int $organizationId,
        OrganizationOperation $operation,
        int $now,
        Closure $mutation,
        ?string $permission = null,
        ?Closure $beforeOrganizationLock = null,
        ?Closure $clock = null,
    ): mixed {
        $this->requireOrganizationIdentity($identity);
        $currentTime = $clock ?? static fn (): int => $now;

        return $this->organizations->transaction(fn (): mixed => $sessions->withCurrentIdentity(
            $identity,
            $currentTime(),
            function () use (
                $sessions,
                $identity,
                $organizationId,
                $operation,
                $mutation,
                $permission,
                $beforeOrganizationLock,
                $currentTime,
            ): mixed {
                if ($beforeOrganizationLock !== null) {
                    $beforeOrganizationLock($this->require($identity, $organizationId, $operation, $currentTime(), $permission));
                }
                [$organization, $role] = $this->loadMembership($identity, $organizationId, lock: true);
                $lockedNow = $currentTime();
                $sessions->requireLiveIdentity($identity, $lockedNow);
                $context = $this->authorizeMembership($identity, $organization, $role, $operation, $lockedNow, $permission);

                return $mutation($context);
            },
        ));
    }

    /**
     * Lifecycle gate only. Pass the organization ID and role from the invitation already authenticated by the
     * dedicated invitation flow. For acceptance, take the target account/phone lock first and call with lock: true
     * within the same transaction as the invitation recheck, seat checks and member creation.
     */
    public function requireInvitation(int $organizationId, OrganizationRole $invitedRole, int $now, bool $lock = false): Organization
    {
        $organization = $this->organizations->find($organizationId, $lock) ?? throw BusinessException::notFound();
        $this->requireAvailable($organization);
        if ($invitedRole === OrganizationRole::Member && !$organization->activated) {
            throw new BusinessException('组织尚未激活', 'organization_pending', 403);
        }
        if ($invitedRole === OrganizationRole::Admin || ($invitedRole === OrganizationRole::Owner && $organization->activated)) {
            throw new BusinessException('邀请已失效', 'invitation_invalid', 409);
        }
        $firstOwner = $invitedRole === OrganizationRole::Owner;
        $this->requireSubscription($organization, $now, allowBeforeStart: $firstOwner, allowAfterExpiry: false);

        return $organization;
    }

    private function requireOrganizationIdentity(SessionIdentity $identity): void
    {
        if ($identity->purpose !== SessionService::PURPOSE_ORGANIZATION) {
            throw new BusinessException('认证令牌用途不匹配', 'token_purpose_mismatch', 403);
        }
    }

    /** @return array{?Organization, ?OrganizationRole} */
    private function loadMembership(SessionIdentity $identity, int $organizationId, bool $lock): array
    {
        $organization = $this->organizations->find($organizationId, $lock);
        $role = $organization === null ? null : $this->memberships->findRole($organizationId, $identity->accountId, $lock);

        return [$organization, $role];
    }

    private function authorizeMembership(
        SessionIdentity $identity,
        ?Organization $organization,
        ?OrganizationRole $role,
        OrganizationOperation $operation,
        int $now,
        ?string $permission,
    ): OrganizationAccess {
        if ($organization === null || $role === null) {
            throw BusinessException::notFound();
        }
        $this->requireAvailable($organization);
        if (!$organization->activated) {
            throw new BusinessException('组织尚未激活', 'organization_pending', 403);
        }
        $this->requireSubscription($organization, $now, allowBeforeStart: false, allowAfterExpiry: $operation->allowedAfterExpiry());
        if (!$operation->allowsRole($role)) {
            if ($operation === OrganizationOperation::MemberExit) {
                throw new BusinessException('所有者退出前须转移所有权', 'last_owner_required', 409);
            }
            throw BusinessException::forbidden();
        }
        if ($operation->isBusiness() && ($permission === null || !isset($this->businessPermissions[$permission])
            || ($role !== OrganizationRole::Owner && !in_array($role, $this->businessPermissions[$permission], true)))) {
            throw BusinessException::forbidden();
        }

        return new OrganizationAccess($organization, $identity->accountId, $role);
    }

    private function requireAvailable(Organization $organization): void
    {
        if ($organization->archived) {
            throw new BusinessException('组织已归档', 'organization_archived', 403);
        }
        if ($organization->suspended) {
            throw new BusinessException('组织已停用', 'organization_suspended', 403);
        }
    }

    private function requireSubscription(Organization $organization, int $now, bool $allowBeforeStart, bool $allowAfterExpiry): void
    {
        $status = $organization->subscription->statusAt($now);
        if ($status === SubscriptionWindow::INACTIVE && !$allowBeforeStart) {
            throw new BusinessException('组织订阅尚未生效', 'subscription_inactive', 403);
        }
        if ($status === SubscriptionWindow::EXPIRED && !$allowAfterExpiry) {
            throw new BusinessException('组织订阅已到期', 'subscription_expired', 403);
        }
    }
}
