<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitation;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use LogicException;
use stdClass;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;

final class IlluminateInvitationRepository implements InvitationRepositoryInterface
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function find(int $organizationId, int $id, bool $lock = false): ?Invitation
    {
        return $this->read($this->organizationInvitations($organizationId)->where('id', $id), $lock);
    }

    public function findBySelector(string $selector): ?Invitation
    {
        return $this->read($this->invitations()->where('selector', $selector), false);
    }

    public function latestOwner(int $organizationId, bool $lock = false): ?Invitation
    {
        return $this->read($this->organizationInvitations($organizationId)->where('role', OrganizationRole::Owner->value)
            ->orderByDesc('id'), $lock);
    }

    public function insert(int $organizationId, MainlandMobileNumber $phone, OrganizationRole $role, int $expiresAt): Invitation
    {
        $this->requireTransaction();
        $selector = bin2hex(random_bytes(16));
        $id = $this->invitations()->insertGetId([
            'organization_id' => $organizationId,
            'phone' => $phone->e164,
            'role' => $role->value,
            'selector' => $selector,
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
            'status' => Invitation::PENDING,
            'reserved' => true,
        ]);

        return new Invitation((int) $id, $organizationId, $phone, $role, $selector, $expiresAt, Invitation::PENDING, true, null);
    }

    public function revoke(Invitation $invitation): void
    {
        $this->requireTransaction();
        $changed = $this->organizationInvitations($invitation->organizationId)->where('id', $invitation->id)
            ->where('status', Invitation::PENDING)->update(['status' => Invitation::REVOKED, 'reserved' => false]);
        if ($changed !== 1) {
            throw Invitation::invalid();
        }
    }

    public function markReplaced(Invitation $invitation, int $now): void
    {
        $this->requireTransaction();
        $changed = $this->organizationInvitations($invitation->organizationId)->where('id', $invitation->id)
            ->whereIn('status', [Invitation::PENDING, Invitation::REVOKED])->whereNull('replaced_at')
            ->update(['status' => Invitation::REVOKED, 'reserved' => false, 'replaced_at' => gmdate('Y-m-d H:i:s', $now)]);
        if ($changed !== 1) {
            throw Invitation::invalid();
        }
    }

    public function revokePending(int $organizationId): void
    {
        $this->requireTransaction();
        $this->organizationInvitations($organizationId)->where('status', Invitation::PENDING)
            ->update(['status' => Invitation::REVOKED, 'reserved' => false]);
    }

    public function consume(Invitation $invitation, int $accountId): void
    {
        $this->requireTransaction();
        $changed = $this->organizationInvitations($invitation->organizationId)->where('id', $invitation->id)
            ->where('status', Invitation::PENDING)->update([
                'status' => Invitation::ACCEPTED, 'reserved' => false, 'account_id' => $accountId,
            ]);
        if ($changed !== 1) {
            throw Invitation::invalid();
        }
    }

    private function invitations(): Builder
    {
        return $this->connection->table('starter_invitations')->useWritePdo();
    }

    private function organizationInvitations(int $organizationId): Builder
    {
        return $this->invitations()->where('organization_id', $organizationId);
    }

    private function read(Builder $query, bool $lock): ?Invitation
    {
        if ($lock) {
            $this->requireTransaction();
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row === null ? null : self::hydrate($row);
    }

    private static function hydrate(stdClass $row): Invitation
    {
        return new Invitation(
            (int) $row->id,
            (int) $row->organization_id,
            MainlandMobileNumber::fromInput($row->phone),
            OrganizationRole::from($row->role),
            $row->selector,
            strtotime($row->expires_at . ' UTC'),
            $row->status,
            (bool) $row->reserved,
            $row->replaced_at === null ? null : strtotime($row->replaced_at . ' UTC'),
        );
    }

    private function requireTransaction(): void
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException('Invitation mutation and locking require an organization transaction.');
        }
    }
}
