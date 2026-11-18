<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\operator;

use Closure;
use Throwable;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\rebind\PhoneChangeFlow;
use Z0x\WebmanStarter\saas\rebind\PhoneChangeRepositoryInterface;
use Z0x\WebmanStarter\saas\rebind\PhoneChangeService;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class OperatorRecoveryService
{
    public function __construct(
        private readonly OperatorRecoveryRepositoryInterface $repository,
        private readonly PhoneChangeRepositoryInterface $flows,
        private readonly PhoneChangeService $changes,
        private readonly OperatorRecoveryEvidenceVerifierInterface $verifier,
    ) {
    }

    /** @param Closure():int $clock */
    public function startManual(
        SessionIdentity $operator,
        int $accountId,
        MainlandMobileNumber $newPhone,
        OperatorRecoveryEvidence $evidence,
        Closure $clock,
    ): string {
        return $this->start($operator, $accountId, $newPhone, $evidence, $clock);
    }

    /** @param Closure():int $clock */
    public function startOffline(
        string $operator,
        MainlandMobileNumber $newPhone,
        OperatorRecoveryEvidence $evidence,
        Closure $clock,
    ): string {
        if (preg_match('/\A(?:uid:[0-9]+|deployment:[A-Za-z0-9_.-]{1,64})\z/', $operator) !== 1) {
            throw BusinessException::forbidden();
        }

        return $this->start($operator, null, $newPhone, $evidence, $clock);
    }

    /** @param Closure():int $clock */
    public function sendNew(string $id, SessionIdentity|string $operator, string $ip, Closure $clock): string
    {
        return $this->changes->sendNew($id, $this->authorize($operator, $clock), $ip, $clock);
    }

    /** @param Closure():int $clock */
    public function verifyNew(
        string $id,
        SessionIdentity|string $operator,
        string $challenge,
        #[\SensitiveParameter] string $code,
        Closure $clock,
    ): int {
        return $this->changes->verifyNew($id, $this->authorize($operator, $clock), $challenge, $code, $clock);
    }

    /** @param Closure():int $clock */
    public function complete(string $id, SessionIdentity|string $operator, Closure $clock): void
    {
        $this->changes->complete($id, $this->authorize($operator, $clock), $clock);
    }

    /** @param Closure():int $clock */
    private function start(
        SessionIdentity|string $operator,
        ?int $accountId,
        MainlandMobileNumber $newPhone,
        OperatorRecoveryEvidence $evidence,
        Closure $clock,
    ): string {
        $target = $this->repository->withGuard(fn (): array => $this->eligibleTarget($operator, $accountId));
        if ($target['phone'] === $newPhone->e164) {
            throw BusinessException::validation(['new_phone' => ['新手机号须与当前手机号不同']]);
        }
        if ($this->flows->phoneTaken($newPhone)) {
            throw new BusinessException('该手机号已被其他账号使用', 'phone_already_used', 409);
        }
        $actorAccountId = $operator instanceof SessionIdentity ? $operator->accountId : null;
        $actorRef = is_string($operator) ? $operator : null;
        try {
            // Verifying the case can call a host service. Release database locks before the call.
            $approved = $this->verifier->verify($evidence, $target['id'], $newPhone, $actorAccountId ?? $actorRef);
        } catch (Throwable) {
            $approved = false;
        }
        if (!$approved) {
            throw self::unverifiedEvidence($operator);
        }

        return $this->repository->withGuard(function () use ($operator, $target, $newPhone, $evidence, $actorAccountId, $actorRef, $clock): string {
            $current = $this->eligibleTarget($operator, $target['id']);
            if ($current['phoneEpoch'] !== $target['phoneEpoch'] || $current['phone'] !== $target['phone']) {
                throw $operator instanceof SessionIdentity
                    ? new BusinessException('恢复对象已变更，请重新核验', 'credential_expired', 401)
                    : new BusinessException('恢复对象已变更，请重新核验', 'recovery_target_changed', 409);
            }
            $now = $clock();
            $flow = new PhoneChangeFlow(
                bin2hex(random_bytes(16)), $current['id'], MainlandMobileNumber::fromInput($current['phone']),
                $current['phoneEpoch'], $newPhone, self::mode($operator), $now + PhoneChangeFlow::PROOF_TTL,
                null, $actorAccountId, $actorRef, $evidence->reference,
                recoveryReason: $evidence->reason, evidenceChannel: $evidence->channel,
            );
            $this->flows->create($flow, $now);

            return $flow->id;
        });
    }

    /** @param Closure():int $clock @return Closure(PhoneChangeFlow):void */
    private function authorize(SessionIdentity|string $operator, Closure $clock): Closure
    {
        return function (PhoneChangeFlow $flow) use ($operator, $clock): void {
            // PhoneChangeService invokes this under the platform guard, target account and flow locks.
            if ($flow->mode !== self::mode($operator) || ($operator instanceof SessionIdentity
                ? $flow->actorAccountId !== $operator->accountId : $flow->actorRef !== $operator)) {
                throw BusinessException::notFound();
            }
            if ($flow->reasonRef === null || $flow->recoveryReason === null || $flow->evidenceChannel === null) {
                throw self::unverifiedEvidence($operator);
            }
            $this->eligibleTarget($operator, $flow->accountId);
            $flow->requireSourceProof($clock());
        };
    }

    /** @return array{id:int,phone:string,phoneEpoch:string} */
    private function eligibleTarget(SessionIdentity|string $operator, ?int $accountId): array
    {
        if ($operator instanceof SessionIdentity) {
            if ($operator->purpose !== SessionService::PURPOSE_PLATFORM) {
                throw new BusinessException('认证令牌用途不匹配', 'token_purpose_mismatch', 403);
            }
            $actor = $this->repository->lockAccount($operator->accountId);
            if (($actor['sessionEpoch'] ?? null) !== $operator->accountEpoch) {
                throw BusinessException::tokenInvalid();
            }
            $activeEpoch = $this->repository->lockActiveQualificationEpoch($operator->accountId);
            if ($activeEpoch === null) {
                throw BusinessException::forbidden();
            }
            if ($activeEpoch !== $operator->platformEpoch) {
                throw BusinessException::tokenInvalid();
            }
            if ($this->repository->activeAdministratorIds() === [$accountId]) {
                throw new BusinessException('唯一平台管理员须通过离线命令恢复', 'forbidden', 403);
            }
        } else {
            $active = $this->repository->activeAdministratorIds();
            if (count($active) !== 1 || ($accountId !== null && $accountId !== $active[0])) {
                throw new BusinessException('仅有一位有效平台管理员时才能使用离线恢复', 'offline_recovery_not_eligible', 409);
            }
            $accountId = $active[0];
        }

        $account = $accountId === null ? null : $this->repository->lockAccount($accountId);
        if ($account === null) {
            throw BusinessException::notFound();
        }
        if ($account['hasVerifiedEmail']) {
            throw $operator instanceof SessionIdentity
                ? new BusinessException('已验证邮箱的账号须通过邮箱恢复', 'forbidden', 403)
                : new BusinessException('已验证邮箱的账号须通过邮箱恢复', 'verified_email_exists', 409);
        }

        return ['id' => $accountId, 'phone' => $account['phone'], 'phoneEpoch' => $account['phoneEpoch']];
    }

    private static function unverifiedEvidence(SessionIdentity|string $operator): BusinessException
    {
        return new BusinessException('无法确认账号归属的核验依据',
            $operator instanceof SessionIdentity ? 'forbidden' : 'recovery_evidence_unverified', 403);
    }

    private static function mode(SessionIdentity|string $operator): string
    {
        return $operator instanceof SessionIdentity ? PhoneChangeFlow::MANUAL_RECOVERY : PhoneChangeFlow::OFFLINE_RECOVERY;
    }
}
