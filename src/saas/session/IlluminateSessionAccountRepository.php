<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\session;

use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final class IlluminateSessionAccountRepository implements SessionAccountRepositoryInterface
{
    private const ACCOUNT_COLUMNS = ['session_epoch', 'retained_session_id', 'retained_session_epoch', 'retained_at_epoch'];
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function currentState(int $accountId, bool $platform): ?array
    {
        $this->requireOutsideTransaction();
        $query = $this->connection->table('starter_accounts as account')
            ->useWritePdo()
            ->where('account.id', $accountId)
            ->select(array_map(static fn (string $column): string => 'account.' . $column, self::ACCOUNT_COLUMNS));

        if ($platform) {
            $query->leftJoin('starter_platform_admins as admin', static function ($join): void {
                $join->on('admin.account_id', '=', 'account.id')->where('admin.status', 'active');
            })->addSelect('admin.session_epoch as platform_epoch');
        }

        $state = $query->first();
        if ($state === null) {
            return null;
        }

        return self::state($state, isset($state->platform_epoch) ? (string) $state->platform_epoch : null);
    }

    public function withLockedState(int $accountId, bool $platform, Closure $operation): mixed
    {
        // Nested calls keep the locks until the caller commits. Issuance must use one attempt
        // because the callback may already have published a Redis record.
        return $this->connection->transaction(function () use ($accountId, $platform, $operation): mixed {
            $account = $this->connection->table('starter_accounts')->where('id', $accountId)
                ->lockForUpdate()->first(self::ACCOUNT_COLUMNS);
            if ($account === null) {
                return $operation(null);
            }

            $admin = $platform
                ? $this->connection->table('starter_platform_admins')->where('account_id', $accountId)
                    ->lockForUpdate()->first(['status', 'session_epoch'])
                : null;

            return $operation(self::state($account, $admin?->status === 'active' ? (string) $admin->session_epoch : null));
        });
    }

    /** @return array{accountEpoch:string,platformEpoch:?string,retainedId:?string,retainedEpoch:?string,retainedAt:?string} */
    private static function state(object $account, ?string $platformEpoch): array
    {
        return [
            'accountEpoch' => (string) $account->session_epoch,
            'platformEpoch' => $platformEpoch,
            'retainedId' => $account->retained_session_id,
            'retainedEpoch' => isset($account->retained_session_epoch) ? (string) $account->retained_session_epoch : null,
            'retainedAt' => isset($account->retained_at_epoch) ? (string) $account->retained_at_epoch : null,
        ];
    }

    private function requireOutsideTransaction(): void
    {
        if ($this->connection->transactionLevel() !== 0) {
            throw new LogicException('Session authentication requires a database connection outside a caller transaction.');
        }
    }
}
