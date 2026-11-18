<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\email;

use Closure;
use Illuminate\Database\ConnectionInterface;
use PDOException;
use Z0x\WebmanStarter\saas\infrastructure\IlluminatePlatformAdminRepository;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class IlluminateEmailAccountRepository implements EmailAccountRepositoryInterface
{
    private readonly IlluminatePlatformAdminRepository $auditRepository;

    public function __construct(private readonly ConnectionInterface $connection)
    {
        $this->auditRepository = new IlluminatePlatformAdminRepository($connection);
    }

    public function account(int $accountId): ?array
    {
        return $this->readAccount($accountId, false);
    }

    public function emailOwner(EmailAddress $email): ?int
    {
        $id = $this->connection->table('starter_accounts')->useWritePdo()->where('email', $email->value)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function withLockedAccount(int $accountId, Closure $operation): mixed
    {
        return $this->connection->transaction(fn (): mixed => $operation($this->readAccount($accountId, true)));
    }

    public function bind(int $accountId, EmailAddress $email, int $now): void
    {
        $utc = gmdate('Y-m-d H:i:s', $now);
        try {
            $this->connection->table('starter_accounts')->where('id', $accountId)->increment('email_epoch', 1, [
                'email' => $email->value,
                'email_verified_at' => $utc,
                'updated_at' => $utc,
            ]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062
                && str_contains((string) ($exception->errorInfo[2] ?? ''), 'starter_accounts_email_unique')) {
                throw new BusinessException('邮箱已被其他账号使用', 'email_already_used', 409);
            }
            throw $exception;
        }

        $this->audit('email_bound', $accountId, ['purpose' => EmailVerification::BIND], $now);
    }

    public function setPasswordHash(int $accountId, #[\SensitiveParameter] string $hash, int $now): void
    {
        $this->connection->table('starter_accounts')->where('id', $accountId)->update([
            'password_hash' => $hash,
            'updated_at' => gmdate('Y-m-d H:i:s', $now),
        ]);
    }

    public function audit(string $action, int $accountId, array $metadata, int $now): void
    {
        $this->auditRepository->appendAudit($action, $accountId, $accountId, $metadata, gmdate('Y-m-d H:i:s', $now));
    }

    private function readAccount(int $accountId, bool $lock): ?array
    {
        $query = $this->connection->table('starter_accounts')->useWritePdo()->where('id', $accountId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first(['id', 'email', 'email_epoch', 'email_verified_at', 'password_hash']);

        return $row === null ? null : [
            'id' => (int) $row->id,
            'email' => $row->email,
            'email_epoch' => (int) $row->email_epoch,
            'email_verified_at' => $row->email_verified_at,
            'password_hash' => $row->password_hash,
        ];
    }
}
