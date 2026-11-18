<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\profile;

use Illuminate\Database\ConnectionInterface;

final class IlluminateProfileRepository implements ProfileRepositoryInterface
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function get(int $accountId): ?array
    {
        $account = $this->connection->table('starter_accounts')
            ->useWritePdo()
            ->where('id', $accountId)
            ->first(['id', 'display_name']);

        return $account === null ? null : [
            'account_id' => (int) $account->id,
            'display_name' => $account->display_name,
        ];
    }

    public function update(int $accountId, ?string $displayName): ?array
    {
        return $this->connection->transaction(function () use ($accountId, $displayName): ?array {
            $this->connection->table('starter_accounts')->where('id', $accountId)
                ->update(['display_name' => $displayName]);

            return $this->get($accountId);
        });
    }
}
