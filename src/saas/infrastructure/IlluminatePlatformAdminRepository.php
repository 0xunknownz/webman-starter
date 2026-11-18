<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\infrastructure;

use Closure;
use Illuminate\Database\ConnectionInterface;
use JsonException;
use PDOException;
use RuntimeException;
use Z0x\WebmanStarter\saas\contract\PlatformAdminRepositoryInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class IlluminatePlatformAdminRepository implements PlatformAdminRepositoryInterface
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function withGuard(Closure $operation): mixed
    {
        return $this->connection->transaction(function () use ($operation): mixed {
            $guard = $this->connection->table('starter_platform_guard')
                ->where('id', 1)
                ->lockForUpdate()
                ->first(['id']);

            if ($guard === null) {
                throw new RuntimeException('The platform guard row is missing.');
            }

            return $operation();
        }, 3);
    }

    public function hasActiveAdministrator(): bool
    {
        return $this->connection->table('starter_platform_admins')
            ->where('status', 'active')
            ->lockForUpdate()
            ->first(['account_id']) !== null;
    }

    public function findOrCreateAccount(MainlandMobileNumber $phone, string $now): array
    {
        $accounts = $this->connection->table('starter_accounts');
        $account = $accounts->where('phone', $phone->e164)->lockForUpdate()->first(['id']);

        if ($account !== null) {
            return ['id' => (int) $account->id, 'created' => false];
        }

        $accountId = $this->connection->table('starter_accounts')->insertGetId([
            'phone' => $phone->e164,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => (int) $accountId, 'created' => true];
    }

    public function grantAdministrator(int $accountId, string $now): void
    {
        $this->connection->table('starter_platform_admins')->upsert([
            'account_id' => $accountId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['account_id'], ['status', 'updated_at']);
    }

    public function appendAudit(
        string $action,
        ?int $actorAccountId,
        ?int $targetAccountId,
        array $redactedMetadata,
        string $now
    ): void {
        try {
            $written = $this->connection->table('starter_audit_logs')->insert([
                'action' => $action,
                'actor_account_id' => $actorAccountId,
                'target_account_id' => $targetAccountId,
                'metadata' => json_encode($redactedMetadata, JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        } catch (PDOException | JsonException) {
            $written = false;
        }

        if (!$written) {
            throw new BusinessException('The platform audit record was not written.', 'audit_unavailable', 503);
        }
    }
}
