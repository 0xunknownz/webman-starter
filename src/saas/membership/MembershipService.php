<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\membership;

use Closure;
use Z0x\WebmanStarter\saas\contract\OrganizationAuditInterface;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccess;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccessService;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationOperation;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class MembershipService
{
    /** @param Closure(): int $clock */
    public function __construct(
        private readonly OrganizationAccessService $access,
        private readonly SessionService $sessions,
        private readonly MembershipRepositoryInterface $memberships,
        private readonly OrganizationAuditInterface $audit,
        private readonly Closure $clock,
    ) {
    }

    /** @return array{organization_id: int, name: string, members: list<array{display_name: string, role: string, account_id?: int, phone?: string, email?: ?string}>} */
    public function listMembers(SessionIdentity $identity, int $organizationId): array
    {
        $context = $this->access->require($identity, $organizationId, OrganizationOperation::MemberList, ($this->clock)());
        $organization = $context->organization;
        $includeContacts = OrganizationOperation::MemberContacts->allowsRole($context->role);

        return [
            'organization_id' => $organization->id,
            'name' => $organization->name,
            'members' => $this->memberships->listMembers($organization->id, $includeContacts),
        ];
    }

    public function changeRole(SessionIdentity $identity, int $organizationId, int $accountId, mixed $requestedRole): void
    {
        $this->access->write($this->sessions, $identity, $organizationId, OrganizationOperation::MemberRoleChange, ($this->clock)(),
            function (OrganizationAccess $context) use ($accountId, $requestedRole): void {
                $organizationId = $context->organization->id;
                $previous = $this->memberships->findRole($organizationId, $accountId, lock: true)
                    ?? throw BusinessException::notFound();
                $role = is_string($requestedRole) ? OrganizationRole::tryFrom($requestedRole) : null;
                if ($role === null) {
                    throw BusinessException::validation(['role' => ['角色无效']]);
                }
                if ($previous === OrganizationRole::Owner || $role === OrganizationRole::Owner) {
                    throw BusinessException::forbidden();
                }
                if ($previous === $role) {
                    return;
                }
                $this->memberships->updateRole($organizationId, $accountId, $role);
                $this->audit->append($organizationId, 'member_role_changed', $context->accountId, $accountId, [
                    'previous_role' => $previous->value,
                    'new_role' => $role->value,
                    'result' => 'success',
                ], ($this->clock)());
            }, clock: $this->clock);
    }

    public function remove(SessionIdentity $identity, int $organizationId, int $accountId): void
    {
        $this->access->write($this->sessions, $identity, $organizationId, OrganizationOperation::MemberRemove, ($this->clock)(),
            function (OrganizationAccess $context) use ($accountId): void {
                $role = $this->memberships->findRole($context->organization->id, $accountId, lock: true)
                    ?? throw BusinessException::notFound();
                if ($role === OrganizationRole::Owner) {
                    throw new BusinessException('组织必须保留所有者', 'last_owner_required', 409);
                }
                if ($context->role === OrganizationRole::Admin && $role !== OrganizationRole::Member) {
                    throw BusinessException::forbidden();
                }
                $this->endMembership($context, $accountId, $role, 'member_removed');
            }, clock: $this->clock);
    }

    public function exit(SessionIdentity $identity, int $organizationId): void
    {
        $this->access->write($this->sessions, $identity, $organizationId, OrganizationOperation::MemberExit, ($this->clock)(),
            function (OrganizationAccess $context): void {
                $this->endMembership($context, $context->accountId, $context->role, 'member_exited');
            }, clock: $this->clock);
    }

    private function endMembership(OrganizationAccess $context, int $accountId, OrganizationRole $role, string $action): void
    {
        $organizationId = $context->organization->id;
        $now = ($this->clock)();
        $this->memberships->remove($organizationId, $accountId, $now);
        $this->audit->append($organizationId, $action, $context->accountId, $accountId, [
            'previous_role' => $role->value,
            'result' => 'success',
        ], $now);
    }
}
