<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\rebind;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Z0x\WebmanStarter\saas\contract\PlatformAdminRepositoryInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\infrastructure\IlluminatePlatformAdminRepository;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class IlluminatePhoneChangeRepository implements PhoneChangeRepositoryInterface
{
    private readonly PlatformAdminRepositoryInterface $audit;

    public function __construct(
        private readonly ConnectionInterface $connection,
        ?PlatformAdminRepositoryInterface $audit = null,
    ) {
        $this->audit = $audit ?? new IlluminatePlatformAdminRepository($connection);
    }

    public function account(int $id): ?array
    {
        $row = $this->connection->table('starter_accounts')->useWritePdo()->where('id', $id)
            ->first(['phone', 'phone_epoch', 'session_epoch']);

        return $row === null ? null : [
            'phone' => $row->phone,
            'phoneEpoch' => (string) $row->phone_epoch,
            'sessionEpoch' => (string) $row->session_epoch,
        ];
    }

    public function create(PhoneChangeFlow $flow, int $now): void
    {
        try {
            $this->connection->table('starter_phone_changes')->insert([
                'id' => $flow->id,
                'account_id' => $flow->accountId,
                'old_phone' => $flow->oldPhone->e164,
                'old_phone_epoch' => $flow->oldPhoneEpoch,
                'new_phone' => $flow->newPhone->e164,
                'mode' => $flow->mode,
                'source_verified_until' => $flow->sourceVerifiedUntil,
                'new_verified_until' => $flow->newVerifiedUntil,
                'actor_account_id' => $flow->actorAccountId,
                'actor_ref' => $flow->actorRef,
                'reason_ref' => $flow->reasonRef,
                'recovery_reason' => $flow->recoveryReason,
                'evidence_channel' => $flow->evidenceChannel,
                'created_at' => gmdate('Y-m-d H:i:s', $now),
            ]);
        } catch (QueryException) {
            throw self::storageError();
        }
    }

    public function withFlow(string $id, ?int $accountId, Closure $operation, int $attempts = 1): mixed
    {
        if (preg_match('/\A[0-9a-f]{32}\z/', $id) !== 1) {
            throw BusinessException::notFound();
        }

        try {
            $query = $this->connection->table('starter_phone_changes')->useWritePdo()->where('id', $id);
            if ($accountId !== null) {
                $query->where('account_id', $accountId);
            }
            $start = (clone $query)->first(['account_id', 'old_phone', 'old_phone_epoch', 'mode']);
            if ($start === null) {
                throw BusinessException::notFound();
            }

            return $this->connection->transaction(function () use ($query, $start, $operation): mixed {
                if (self::operatorMode($start->mode)) {
                    // Match the platform mutation order. Take the guard before any account or qualification locks.
                    $guard = $this->connection->table('starter_platform_guard')->where('id', 1)
                        ->lockForUpdate()->first(['id']);
                    if ($guard === null) {
                        throw self::storageError();
                    }
                }

                // D2 acceptance must lock the account by the canonical invitation phone (unique index)
                // before locking any invitation rows, then revalidate the invitation and proof under lock.
                // The locking account read also serializes refresh (which takes the primary-key row lock).
                $account = $this->connection->table('starter_accounts')->where('phone', $start->old_phone)
                    ->lockForUpdate()->first(['id', 'phone_epoch', 'session_epoch']);
                if ($account === null || (int) $account->id !== (int) $start->account_id
                    || (string) $account->phone_epoch !== (string) $start->old_phone_epoch) {
                    throw BusinessException::notFound();
                }

                $row = (clone $query)->lockForUpdate()->first();
                if ($row === null || $row->mode !== $start->mode) {
                    throw BusinessException::notFound();
                }
                $flow = new PhoneChangeFlow(
                    $row->id,
                    (int) $row->account_id,
                    MainlandMobileNumber::fromInput($row->old_phone),
                    (string) $row->old_phone_epoch,
                    MainlandMobileNumber::fromInput($row->new_phone),
                    $row->mode,
                    isset($row->source_verified_until) ? (int) $row->source_verified_until : null,
                    isset($row->new_verified_until) ? (int) $row->new_verified_until : null,
                    isset($row->actor_account_id) ? (int) $row->actor_account_id : null,
                    $row->actor_ref,
                    $row->reason_ref,
                    $row->recovery_reason,
                    $row->evidence_channel,
                );

                return $operation($flow, (string) $account->session_epoch);
            }, $attempts);
        } catch (QueryException) {
            throw self::storageError();
        }
    }

    public function platformEpoch(int $accountId): ?string
    {
        $query = $this->connection->table('starter_platform_admins')->useWritePdo()
            ->where('account_id', $accountId)->where('status', 'active');
        if ($this->connection->transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $epoch = $query->value('session_epoch');

        return $epoch === null ? null : (string) $epoch;
    }

    public function phoneTaken(MainlandMobileNumber $phone, bool $lock = false): bool
    {
        $query = $this->connection->table('starter_accounts')->useWritePdo()->where('phone', $phone->e164);
        if ($lock) {
            // The unique index remains authoritative if another writer wins the absent-key race.
            $query->lockForUpdate();
        }
        if ($lock) {
            return $query->first(['id']) !== null;
        }
        try {
            return $query->first(['id']) !== null;
        } catch (QueryException) {
            throw self::storageError();
        }
    }

    public function recordProof(PhoneChangeFlow $flow, bool $new, int $validUntil): void
    {
        $this->connection->table('starter_phone_changes')->where('id', $flow->id)
            ->update([$new ? 'new_verified_until' : 'source_verified_until' => $validUntil]);
    }

    public function commitChange(PhoneChangeFlow $flow, int $now): void
    {
        $utc = gmdate('Y-m-d H:i:s', $now);
        try {
            $this->connection->table('starter_accounts')->where('id', $flow->accountId)->update([
                'phone' => $flow->newPhone->e164,
                'phone_epoch' => $this->connection->raw('phone_epoch + 1'),
                'session_epoch' => $this->connection->raw('session_epoch + 1'),
                'retained_session_id' => null,
                'retained_session_epoch' => null,
                'retained_at_epoch' => null,
                'updated_at' => $utc,
            ]);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new BusinessException('该手机号已被其他账号使用', 'phone_already_used', 409);
            }
            throw $exception;
        }

        $this->connection->table('starter_invitations')->where('phone', $flow->oldPhone->e164)
            ->where('status', 'pending')->update(['status' => 'revoked', 'reserved' => false]);
        // A different flow might have verified this destination before the departing account acquired it.
        // Removing the flow invalidates both its Redis challenge and its SQL proof in this same commit.
        $this->connection->table('starter_phone_changes')->where('new_phone', $flow->oldPhone->e164)->delete();
        $this->connection->table('starter_phone_changes')->where('id', $flow->id)->delete();

        $metadata = [
            'method' => $flow->mode,
            'old_phone_masked' => $flow->oldPhone->masked(),
            'new_phone_masked' => $flow->newPhone->masked(),
            'result' => 'success',
        ];
        if ($flow->actorRef !== null) {
            $metadata['actor_ref'] = $flow->actorRef;
        }
        if (self::operatorMode($flow->mode)) {
            // Only controlled reason/channel values and a vetted reference belong in the audit.
            $metadata['reason'] = $flow->recoveryReason;
            $metadata['evidence_channel'] = $flow->evidenceChannel;
            $metadata['evidence_ref'] = $flow->reasonRef;
        } elseif ($flow->reasonRef !== null) {
            $metadata['reason_ref'] = $flow->reasonRef;
        }
        $this->audit->appendAudit('phone_changed', $flow->actorAccountId, $flow->accountId, $metadata, $utc);
    }

    public function auditSms(string $action, PhoneChangeFlow $flow, MainlandMobileNumber $phone, int $now): void
    {
        $this->audit->appendAudit($action, $flow->actorAccountId, $flow->accountId, [
            'phone_masked' => $phone->masked(),
            'purpose' => 'rebind',
        ], gmdate('Y-m-d H:i:s', $now));
    }

    private static function operatorMode(string $mode): bool
    {
        return $mode === PhoneChangeFlow::MANUAL_RECOVERY || $mode === PhoneChangeFlow::OFFLINE_RECOVERY;
    }

    private static function storageError(): BusinessException
    {
        // QueryException may embed the bound phone numbers. Use a generic error without a chained cause.
        return new BusinessException('服务内部错误', 'internal_error', 500);
    }
}
