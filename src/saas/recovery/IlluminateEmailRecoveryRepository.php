<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\recovery;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Z0x\WebmanStarter\saas\email\EmailAddress;
use Z0x\WebmanStarter\saas\email\EmailVerification;
use Z0x\WebmanStarter\saas\infrastructure\IlluminatePlatformAdminRepository;
use Z0x\WebmanStarter\saas\rebind\PhoneChangeFlow;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class IlluminateEmailRecoveryRepository implements EmailRecoveryRepositoryInterface
{
    private const ACCOUNT_COLUMNS = ['id', 'email', 'email_verified_at', 'email_epoch', 'phone', 'phone_epoch'];

    private readonly IlluminatePlatformAdminRepository $audit;

    public function __construct(private readonly ConnectionInterface $connection)
    {
        $this->audit = new IlluminatePlatformAdminRepository($connection);
    }

    public function account(EmailAddress $email): ?array
    {
        try {
            $row = $this->connection->table('starter_accounts')->useWritePdo()->where('email', $email->value)
                ->first(self::ACCOUNT_COLUMNS);

            return self::mapAccount($row);
        } catch (QueryException) {
            throw self::storageError();
        }
    }

    public function withLockedAccount(string $phone, Closure $operation): mixed
    {
        try {
            return $this->connection->transaction(function () use ($phone, $operation): mixed {
                $row = $this->connection->table('starter_accounts')->where('phone', $phone)
                    ->lockForUpdate()->first(self::ACCOUNT_COLUMNS);

                return $operation(self::mapAccount($row));
            });
        } catch (QueryException) {
            throw self::storageError();
        }
    }

    public function storeProof(PhoneChangeFlow $flow, string $emailEpoch, string $tokenHash): void
    {
        $this->connection->table('starter_phone_changes')->where('id', $flow->id)->update([
            'recovery_email_epoch' => $emailEpoch,
            'recovery_token_hash' => $tokenHash,
        ]);
    }

    public function lockedProof(PhoneChangeFlow $flow): ?array
    {
        $account = $this->connection->table('starter_accounts')->where('id', $flow->accountId)
            ->whereNotNull('email')->whereNotNull('email_verified_at')->lockForUpdate()->first(['email_epoch']);
        $proof = $this->connection->table('starter_phone_changes')->where('id', $flow->id)
            ->lockForUpdate()->first(['recovery_email_epoch', 'recovery_token_hash']);

        return $account === null || $proof === null || $proof->recovery_email_epoch === null || $proof->recovery_token_hash === null
            ? null : [
                'email_epoch' => (string) $proof->recovery_email_epoch,
                'current_email_epoch' => (string) $account->email_epoch,
                'token_hash' => $proof->recovery_token_hash,
            ];
    }

    public function auditAttempt(string $action, ?int $accountId, int $now): void
    {
        $this->audit->appendAudit($action, null, $accountId, ['purpose' => EmailVerification::RECOVERY], gmdate('Y-m-d H:i:s', $now));
    }

    /** @return array{id:int,email:?string,email_verified_at:?string,email_epoch:string,phone:string,phone_epoch:string}|null */
    private static function mapAccount(#[\SensitiveParameter] ?object $row): ?array
    {
        return $row === null ? null : [
            'id' => (int) $row->id,
            'email' => $row->email,
            'email_verified_at' => $row->email_verified_at,
            'email_epoch' => (string) $row->email_epoch,
            'phone' => $row->phone,
            'phone_epoch' => (string) $row->phone_epoch,
        ];
    }

    private static function storageError(): BusinessException
    {
        return new BusinessException('服务内部错误', 'internal_error', 500);
    }
}
