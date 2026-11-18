<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\membership;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use LogicException;
use Z0x\WebmanStarter\saas\organizationaccess\IlluminateOrganizationMembershipRepository;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;

final class IlluminateMembershipRepository implements MembershipRepositoryInterface
{
    private readonly IlluminateOrganizationMembershipRepository $roles;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ?MembershipInvalidatorInterface $invalidator = null,
    ) {
        $this->roles = new IlluminateOrganizationMembershipRepository($connection);
    }

    public function findRole(int $organizationId, int $accountId, bool $lock = false): ?OrganizationRole
    {
        return $this->roles->findRole($organizationId, $accountId, $lock);
    }

    public function listMembers(int $organizationId, bool $includeContacts): array
    {
        $query = $this->connection->table('starter_memberships as membership')->useWritePdo()
            ->join('starter_accounts as account', 'account.id', '=', 'membership.account_id')
            ->where('membership.organization_id', $organizationId)
            ->orderBy('membership.account_id')
            ->select('account.display_name', 'membership.role');
        if ($includeContacts) {
            // Membership admission and account phone replacement both require verification of the stored phone.
            $query->addSelect('membership.account_id', 'account.phone')
                ->selectRaw('CASE WHEN account.email_verified_at IS NOT NULL THEN account.email END AS email');
        }

        $members = [];
        foreach ($query->get() as $row) {
            $member = [
                'display_name' => $row->display_name === null || $row->display_name === '' ? '未设置姓名' : $row->display_name,
                'role' => $row->role,
            ];
            if ($includeContacts) {
                $member += ['account_id' => (int) $row->account_id, 'phone' => $row->phone, 'email' => $row->email];
            }
            $members[] = $member;
        }

        return $members;
    }

    public function updateRole(int $organizationId, int $accountId, OrganizationRole $role): void
    {
        $this->lockedMember($organizationId, $accountId)->update(['role' => $role->value]);
    }

    public function remove(int $organizationId, int $accountId, int $now): void
    {
        $removed = $this->lockedMember($organizationId, $accountId)->delete();
        if ($removed !== 1) {
            throw new LogicException('The locked membership could not be removed.');
        }
        $this->invalidator?->invalidatePending($organizationId, $accountId, $now);
    }

    private function lockedMember(int $organizationId, int $accountId): Builder
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException('Membership mutations require an organization transaction.');
        }

        return $this->connection->table('starter_memberships')
            ->where('organization_id', $organizationId)->where('account_id', $accountId);
    }
}
