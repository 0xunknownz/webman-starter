<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\platform;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\infrastructure\IlluminatePlatformAdminRepository;

final class IlluminatePlatformAdminMutationRepository implements PlatformAdminMutationRepositoryInterface
{
    private readonly IlluminatePlatformAdminRepository $admins;

    public function __construct(private readonly ConnectionInterface $connection)
    {
        $this->admins = new IlluminatePlatformAdminRepository($connection);
    }

    public function withGuard(Closure $operation): mixed
    {
        return $this->admins->withGuard($operation);
    }

    public function lockGrantAccounts(int $actorId, MainlandMobileNumber $phone, string $now): array
    {
        // Discover the target ID first so the existing accounts can be locked in ID order.
        $targetId = $this->connection->table('starter_accounts')->where('phone', $phone->e164)->value('id');
        $epoch = $this->lockAccountEpoch($actorId, $targetId === null ? null : (int) $targetId);
        // Resolve against current locked state, including any new account for this phone.
        $target = $this->admins->findOrCreateAccount($phone, $now);

        return ['actor_epoch' => $epoch, 'target_id' => $target['id'], 'created' => $target['created']];
    }

    public function lockAccountEpoch(int $actorId, ?int $targetId): ?string
    {
        $ids = $targetId === null || $targetId === $actorId ? [$actorId] : [$actorId, $targetId];
        sort($ids, SORT_REGULAR);
        $epoch = null;
        foreach ($ids as $id) {
            $account = $this->connection->table('starter_accounts')->where('id', $id)
                ->lockForUpdate()->first(['session_epoch']);
            if ($id === $actorId && $account !== null) {
                $epoch = (string) $account->session_epoch;
            }
        }

        return $epoch;
    }

    public function lockAdministrators(int $actorId, int $targetId): array
    {
        $rows = $this->connection->table('starter_platform_admins')->whereIn('account_id', [$actorId, $targetId])
            ->orderBy('account_id')->lockForUpdate()->get(['account_id', 'status', 'session_epoch']);
        $admins = [];
        foreach ($rows as $row) {
            $admins[(int) $row->account_id] = ['status' => $row->status, 'epoch' => (string) $row->session_epoch];
        }

        return $admins;
    }

    public function hasOtherActiveAdministrator(int $targetId): bool
    {
        return $this->connection->table('starter_platform_admins')->where('status', 'active')
            ->where('account_id', '!=', $targetId)->lockForUpdate()->first(['account_id']) !== null;
    }

    public function grantAdministrator(int $targetId, string $now): void
    {
        $this->admins->grantAdministrator($targetId, $now);
    }

    public function revokeAdministrator(int $targetId, string $now): void
    {
        $this->connection->table('starter_platform_admins')->where('account_id', $targetId)
            ->increment('session_epoch', 1, ['status' => 'revoked', 'updated_at' => $now]);
    }

    public function appendAudit(string $action, int $actorId, int $targetId, array $redactedMetadata, string $now): void
    {
        $this->admins->appendAudit($action, $actorId, $targetId, $redactedMetadata, $now);
    }
}
