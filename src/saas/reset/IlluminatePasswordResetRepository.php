<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\reset;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Z0x\WebmanStarter\saas\email\EmailAddress;
use Z0x\WebmanStarter\saas\email\EmailVerification;
use Z0x\WebmanStarter\saas\infrastructure\IlluminatePlatformAdminRepository;

final class IlluminatePasswordResetRepository implements PasswordResetRepositoryInterface
{
    private const ACCOUNT_COLUMNS = ['id', 'email', 'email_verified_at', 'email_epoch', 'password_hash'];

    private readonly IlluminatePlatformAdminRepository $audit;

    public function __construct(private readonly ConnectionInterface $connection)
    {
        $this->audit = new IlluminatePlatformAdminRepository($connection);
    }

    public function account(EmailAddress $email): ?array
    {
        $row = $this->connection->table('starter_accounts')->useWritePdo()->where('email', $email->value)
            ->first(self::ACCOUNT_COLUMNS);

        return self::mapAccount($row);
    }

    public function withLockedAccount(int $accountId, Closure $operation): void
    {
        $this->connection->transaction(function () use ($accountId, $operation): void {
            $row = $this->connection->table('starter_accounts')->where('id', $accountId)
                ->lockForUpdate()->first(self::ACCOUNT_COLUMNS);
            $operation(self::mapAccount($row));
        });
    }

    public function reset(int $accountId, #[\SensitiveParameter] string $passwordHash, int $now): void
    {
        $utc = gmdate('Y-m-d H:i:s', $now);
        $this->connection->table('starter_accounts')->where('id', $accountId)->increment('session_epoch', 1, [
            'password_hash' => $passwordHash,
            'retained_session_id' => null,
            'retained_session_epoch' => null,
            'retained_at_epoch' => null,
            'updated_at' => $utc,
        ]);
        $this->audit->appendAudit('password_reset', $accountId, $accountId, ['purpose' => EmailVerification::RESET], $utc);
    }

    public function auditAttempt(string $action, ?int $accountId, int $now): void
    {
        $this->audit->appendAudit($action, null, $accountId, ['purpose' => EmailVerification::RESET], gmdate('Y-m-d H:i:s', $now));
    }

    /** @return array{id:int,email:?string,email_verified_at:?string,email_epoch:string,password_hash:?string}|null */
    private static function mapAccount(#[\SensitiveParameter] ?object $row): ?array
    {
        return $row === null ? null : [
            'id' => (int) $row->id,
            'email' => $row->email,
            'email_verified_at' => $row->email_verified_at,
            'email_epoch' => (string) $row->email_epoch,
            'password_hash' => $row->password_hash,
        ];
    }
}
