<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\emailchange;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Z0x\WebmanStarter\saas\email\EmailAddress;
use Z0x\WebmanStarter\saas\infrastructure\IlluminatePlatformAdminRepository;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class IlluminateEmailChangeRepository implements EmailChangeRepositoryInterface
{
    private readonly IlluminatePlatformAdminRepository $audit;

    public function __construct(private readonly ConnectionInterface $connection)
    {
        $this->audit = new IlluminatePlatformAdminRepository($connection);
    }

    public function withAccount(SessionIdentity $identity, Closure $operation, int $attempts = 1): mixed
    {
        try {
            return $this->connection->transaction(function () use ($identity, $operation): mixed {
                $account = $this->connection->table('starter_accounts')->where('id', $identity->accountId)
                    ->lockForUpdate()->first(['email', 'email_verified_at', 'email_epoch', 'phone', 'phone_epoch', 'session_epoch']);
                if ($account === null || (string) $account->session_epoch !== $identity->accountEpoch) {
                    throw BusinessException::tokenInvalid();
                }
                if ($identity->platformEpoch !== null) {
                    $admin = $this->connection->table('starter_platform_admins')->where('account_id', $identity->accountId)
                        ->lockForUpdate()->first(['status', 'session_epoch']);
                    if ($admin?->status !== 'active' || (string) $admin->session_epoch !== $identity->platformEpoch) {
                        throw BusinessException::tokenInvalid();
                    }
                }

                return $operation([
                    'email' => $account->email,
                    'emailVerified' => $account->email_verified_at !== null,
                    'emailEpoch' => (string) $account->email_epoch,
                    'phone' => $account->phone,
                    'phoneEpoch' => (string) $account->phone_epoch,
                ]);
            }, $attempts);
        } catch (QueryException) {
            // The original SQL exception may contain contact information or session identifiers.
            throw new BusinessException('服务内部错误', 'internal_error', 500);
        }
    }

    public function lockFlow(string $id, SessionIdentity $identity): EmailChangeFlow
    {
        if (preg_match('/\A[0-9a-f]{32}\z/', $id) !== 1) {
            throw BusinessException::notFound();
        }
        $row = $this->connection->table('starter_email_change_flows')->where('id', $id)
            ->where('account_id', $identity->accountId)->where('session_id', $identity->sessionId)
            ->whereNull('completed_at')->lockForUpdate()->first();
        if ($row === null) {
            throw BusinessException::notFound();
        }

        return new EmailChangeFlow(
            $row->id,
            (int) $row->account_id,
            $row->session_id,
            (string) $row->session_epoch,
            (string) $row->email_epoch,
            (string) $row->phone_epoch,
            EmailAddress::fromInput($row->new_email),
            $row->channel,
            $row->source_verified_until === null ? null : (int) strtotime($row->source_verified_until . ' UTC'),
            $row->new_verified_until === null ? null : (int) strtotime($row->new_verified_until . ' UTC'),
        );
    }

    public function create(EmailChangeFlow $flow, int $now): void
    {
        $this->connection->table('starter_email_change_flows')->insert([
            'id' => $flow->id,
            'account_id' => $flow->accountId,
            'session_id' => $flow->sessionId,
            'session_epoch' => $flow->sessionEpoch,
            'email_epoch' => $flow->emailEpoch,
            'phone_epoch' => $flow->phoneEpoch,
            'new_email' => $flow->newEmail->value,
            'channel' => $flow->channel,
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ]);
    }

    public function emailTaken(EmailAddress $email, bool $lock = false): bool
    {
        $query = $this->connection->table('starter_accounts')->useWritePdo()->where('email', $email->value);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first(['id']) !== null;
    }

    public function recordProof(EmailChangeFlow $flow, bool $new, int $deadline): void
    {
        $this->connection->table('starter_email_change_flows')->where('id', $flow->id)
            ->update([$new ? 'new_verified_until' : 'source_verified_until' => gmdate('Y-m-d H:i:s', $deadline)]);
    }

    public function commitChange(EmailChangeFlow $flow, SessionIdentity $identity, int $now): void
    {
        $utc = gmdate('Y-m-d H:i:s', $now);
        try {
            $this->connection->table('starter_accounts')->where('id', $flow->accountId)->update([
                'email' => $flow->newEmail->value,
                'email_verified_at' => $utc,
                'email_epoch' => $this->connection->raw('email_epoch + 1'),
                'retained_session_id' => $identity->sessionId,
                'retained_session_epoch' => $identity->issuedAccountEpoch,
                // MySQL applies single-table assignments left to right. Both values use the previous epoch.
                'retained_at_epoch' => $this->connection->raw('session_epoch + 1'),
                'session_epoch' => $this->connection->raw('session_epoch + 1'),
                'updated_at' => $utc,
            ]);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062
                && str_contains((string) ($exception->errorInfo[2] ?? ''), 'starter_accounts_email_unique')) {
                throw new BusinessException('邮箱已被其他账号使用', 'email_already_used', 409);
            }
            throw $exception;
        }
        $this->connection->table('starter_email_change_flows')->where('id', $flow->id)->update(['completed_at' => $utc]);
        $this->audit->appendAudit('email_changed', $identity->accountId, $flow->accountId, [
            'source_channel' => $flow->channel,
            'result' => 'success',
        ], $utc);
    }

    public function auditAttempt(string $action, int $accountId, string $purpose, string $channel, int $now): void
    {
        $this->audit->appendAudit($action, $accountId, $accountId, [
            'purpose' => $purpose,
            'channel' => $channel,
        ], gmdate('Y-m-d H:i:s', $now));
    }
}
