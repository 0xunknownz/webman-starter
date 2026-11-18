<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\operator;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Z0x\WebmanStarter\saas\infrastructure\IlluminatePlatformAdminRepository;

final class IlluminateOperatorRecoveryRepository implements OperatorRecoveryRepositoryInterface
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

    public function lockAccount(int $accountId): ?array
    {
        $row = $this->connection->table('starter_accounts')->where('id', $accountId)
            ->lockForUpdate()->first(['phone', 'phone_epoch', 'session_epoch', 'email', 'email_verified_at']);

        return $row === null ? null : [
            'phone' => $row->phone,
            'phoneEpoch' => (string) $row->phone_epoch,
            'sessionEpoch' => (string) $row->session_epoch,
            'hasVerifiedEmail' => $row->email !== null && $row->email_verified_at !== null,
        ];
    }

    public function lockActiveQualificationEpoch(int $accountId): ?string
    {
        $row = $this->connection->table('starter_platform_admins')->where('account_id', $accountId)
            ->where('status', 'active')->lockForUpdate()->first(['session_epoch']);

        return $row === null ? null : (string) $row->session_epoch;
    }

    public function activeAdministratorIds(): array
    {
        return $this->connection->table('starter_platform_admins')->useWritePdo()->where('status', 'active')
            ->orderBy('account_id')->limit(2)->pluck('account_id')->map(static fn ($id): int => (int) $id)->all();
    }
}
