<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\ownership;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use LogicException;
use Z0x\WebmanStarter\saas\organizationaccess\IlluminateOrganizationMembershipRepository;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;

final class IlluminateOwnershipTransferRepository implements OwnershipTransferRepositoryInterface
{
    private const TABLE = 'starter_ownership_transfers';
    private readonly IlluminateOrganizationMembershipRepository $memberships;

    public function __construct(private readonly ConnectionInterface $connection)
    {
        $this->memberships = new IlluminateOrganizationMembershipRepository($connection);
    }

    public function create(int $organizationId, int $proposerId, int $recipientId, int $now): OwnershipTransfer
    {
        $this->requireTransaction();
        $expiresAt = $now + OwnershipTransfer::LIFETIME;
        $id = $this->connection->table(self::TABLE)->insertGetId([
            'organization_id' => $organizationId,
            'proposer_account_id' => $proposerId,
            'recipient_account_id' => $recipientId,
            'status' => 'pending',
            'created_at' => self::date($now),
            'expires_at' => self::date($expiresAt),
        ]);

        return new OwnershipTransfer((int) $id, $organizationId, $proposerId, $recipientId, $expiresAt, 'pending');
    }

    public function find(int $organizationId, int $transferId, bool $lock = false): ?OwnershipTransfer
    {
        $query = $this->transfers($organizationId)->where('id', $transferId);
        if ($lock) {
            $this->requireTransaction();
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row === null ? null : self::transfer($row);
    }

    public function inboxOrganizations(int $recipientId): array
    {
        $rows = $this->connection->table(self::TABLE)->useWritePdo()
            ->where('recipient_account_id', $recipientId)->where('status', 'pending')
            ->groupBy('organization_id')->orderBy('organization_id')
            ->select('organization_id')->selectRaw('MIN(expires_at) AS earliest')->get();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->organization_id] = strtotime($row->earliest . ' UTC');
        }

        return $result;
    }

    public function inbox(int $organizationId, int $recipientId, int $now): array
    {
        $rows = $this->connection->table(self::TABLE . ' as transfer')->useWritePdo()
            ->join('starter_memberships as proposer', static function ($join): void {
                $join->on('proposer.organization_id', '=', 'transfer.organization_id')
                    ->on('proposer.account_id', '=', 'transfer.proposer_account_id')->where('proposer.role', 'owner');
            })
            ->join('starter_memberships as recipient', static function ($join): void {
                $join->on('recipient.organization_id', '=', 'transfer.organization_id')
                    ->on('recipient.account_id', '=', 'transfer.recipient_account_id')->whereIn('recipient.role', ['admin', 'member']);
            })
            ->join('starter_accounts as account', 'account.id', '=', 'proposer.account_id')
            ->where('transfer.organization_id', $organizationId)->where('transfer.recipient_account_id', $recipientId)
            ->where('transfer.status', 'pending')->where('transfer.expires_at', '>', self::date($now))
            ->orderBy('transfer.expires_at')->orderBy('transfer.id')
            ->get(['transfer.id', 'transfer.expires_at', 'account.display_name']);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'transfer_id' => (int) $row->id,
                'proposer_display_name' => $row->display_name === null || $row->display_name === '' ? '未设置姓名' : $row->display_name,
                'expires_at' => gmdate(DATE_ATOM, strtotime($row->expires_at . ' UTC')),
            ];
        }

        return $result;
    }

    public function lockExpired(int $organizationId, int $now, ?int $recipientId = null): array
    {
        $this->requireTransaction();
        $rows = $this->pending($organizationId, $recipientId)->where('expires_at', '<=', self::date($now))
            ->orderBy('id')->lockForUpdate()->get();

        return array_map(self::transfer(...), $rows->all());
    }

    public function resolve(OwnershipTransfer $transfer, string $status, int $now): void
    {
        $this->requireTransaction();
        $updated = $this->pending($transfer->organizationId)->where('id', $transfer->id)
            ->update(['status' => $status, 'resolved_at' => self::date($now)]);
        if ($updated !== 1) {
            throw new LogicException('The locked ownership transfer could not be resolved.');
        }
    }

    public function invalidatePending(int $organizationId, int $now, ?int $recipientId = null): void
    {
        $this->requireTransaction();
        $this->pending($organizationId, $recipientId)->update(['status' => 'invalidated', 'resolved_at' => self::date($now)]);
    }

    public function findRole(int $organizationId, int $accountId, bool $lock = false): ?OrganizationRole
    {
        return $this->memberships->findRole($organizationId, $accountId, $lock);
    }

    public function swapOwner(int $organizationId, int $currentOwnerId, int $recipientId): void
    {
        $this->requireTransaction();
        $memberships = $this->connection->table('starter_memberships')->where('organization_id', $organizationId);
        $demoted = (clone $memberships)->where('account_id', $currentOwnerId)->where('role', 'owner')->update(['role' => 'admin']);
        $promoted = $memberships->where('account_id', $recipientId)->whereIn('role', ['admin', 'member'])->update(['role' => 'owner']);
        if ($demoted !== 1 || $promoted !== 1) {
            throw new LogicException('The locked organization roles could not be transferred.');
        }
    }

    private function pending(int $organizationId, ?int $recipientId = null): Builder
    {
        $query = $this->transfers($organizationId)->where('status', 'pending');
        if ($recipientId !== null) {
            $query->where('recipient_account_id', $recipientId);
        }

        return $query;
    }

    private function transfers(int $organizationId): Builder
    {
        return $this->connection->table(self::TABLE)->useWritePdo()->where('organization_id', $organizationId);
    }

    private function requireTransaction(): void
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException('Ownership transfer mutations require an organization transaction.');
        }
    }

    private static function date(int $time): string
    {
        return gmdate('Y-m-d H:i:s', $time);
    }

    private static function transfer(object $row): OwnershipTransfer
    {
        return new OwnershipTransfer((int) $row->id, (int) $row->organization_id, (int) $row->proposer_account_id,
            (int) $row->recipient_account_id, strtotime($row->expires_at . ' UTC'), $row->status);
    }
}
