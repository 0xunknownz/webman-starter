<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\rebind;

use Closure;
use Z0x\WebmanStarter\saas\contract\SmsSenderInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\domain\SubmissionStatus;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\saas\verification\RedisVerification;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class PhoneChangeService
{
    private const PURPOSE = RedisVerification::REBIND;

    public function __construct(
        private readonly PhoneChangeRepositoryInterface $repository,
        private readonly RedisVerification $verification,
        private readonly SmsSenderInterface $sender,
        private readonly SessionService $sessions,
    ) {
    }

    /** @return array{flow_id:string, challenge_id:string} */
    public function start(SessionIdentity $identity, MainlandMobileNumber $newPhone, string $ip, int $now): array
    {
        [$flow, $challenge] = $this->sessions->withCurrentIdentity($identity, $now, function () use ($identity, $newPhone, $ip, $now): array {
            $account = $this->repository->account($identity->accountId);
            if ($account === null) {
                throw BusinessException::tokenInvalid();
            }
            $oldPhone = MainlandMobileNumber::fromInput($account['phone']);
            $this->requireAvailable($oldPhone, $newPhone);
            $flow = new PhoneChangeFlow(
                bin2hex(random_bytes(16)), $identity->accountId, $oldPhone, $account['phoneEpoch'], $newPhone,
                PhoneChangeFlow::NORMAL, null, null, $identity->accountId,
            );
            $challenge = $this->issue($flow, false, $ip, $now);
            if ($challenge !== null) {
                $this->repository->create($flow, $now);
            }

            return [$flow, $challenge];
        });
        $challenge = $this->requireChallenge($flow, false, $challenge, $now);

        return ['flow_id' => $flow->id, 'challenge_id' => $this->deliver($flow, false, $challenge, $now)];
    }

    /** @param Closure():int $clock */
    public function sendOld(string $id, SessionIdentity $identity, string $ip, Closure $clock): string
    {
        return $this->send($id, $identity, false, $ip, $clock);
    }

    /**
     * Recovery callers must provide a trusted authorizer that checks the recovery credential and
     * the flow mode/actor under the transaction, and throws on failure. A flow ID alone is insufficient.
     *
     * @param SessionIdentity|Closure(PhoneChangeFlow):void $authorization
     * @param Closure():int $clock
     */
    public function sendNew(string $id, SessionIdentity|Closure $authorization, string $ip, Closure $clock): string
    {
        return $this->send($id, $authorization, true, $ip, $clock);
    }

    /** @param Closure():int $clock */
    public function verifyOld(
        string $id,
        SessionIdentity $identity,
        string $challenge,
        #[\SensitiveParameter] string $code,
        Closure $clock,
    ): int {
        return $this->verify($id, $identity, false, $challenge, $code, $clock);
    }

    /**
     * @param SessionIdentity|Closure(PhoneChangeFlow):void $authorization See sendNew() for the recovery contract.
     * @param Closure():int $clock
     */
    public function verifyNew(
        string $id,
        SessionIdentity|Closure $authorization,
        string $challenge,
        #[\SensitiveParameter] string $code,
        Closure $clock,
    ): int {
        return $this->verify($id, $authorization, true, $challenge, $code, $clock);
    }

    /**
     * @param SessionIdentity|Closure(PhoneChangeFlow):void $authorization See sendNew() for the recovery contract.
     *     The authorizer may be called again after a deadlock and must not consume external credentials.
     * @param Closure():int $clock
     * @param (Closure(int):void)|null $recheckAuthorization Recheck a recovery session without acquiring SQL locks.
     */
    public function complete(
        string $id,
        SessionIdentity|Closure $authorization,
        Closure $clock,
        ?Closure $recheckAuthorization = null,
    ): void {
        $this->withAuthorizedFlow($id, $authorization, $clock, function (PhoneChangeFlow $flow, int $now) use (
            $authorization, $clock, $recheckAuthorization,
        ): void {
            $flow->requireSourceProof($now);
            $flow->requireNewProof($now);
            $this->requireAvailable($flow->oldPhone, $flow->newPhone, lock: true);
            // The destination lock can block behind a concurrent change.
            $now = $clock();
            if ($authorization instanceof SessionIdentity) {
                $this->sessions->requireLiveIdentity($authorization, $now);
            } elseif ($recheckAuthorization !== null) {
                $recheckAuthorization($now);
            }
            $flow->requireSourceProof($now);
            $flow->requireNewProof($now);
            $this->repository->commitChange($flow, $now);
        }, attempts: 3);
    }

    private function send(string $id, SessionIdentity|Closure $authorization, bool $new, string $ip, Closure $clock): string
    {
        [$flow, $challenge, $now] = $this->withAuthorizedFlow($id, $authorization, $clock, function (PhoneChangeFlow $flow, int $now) use (
            $new, $ip,
        ): array {
            if ($new) {
                $flow->requireSourceProof($now);
                $this->requireAvailable($flow->oldPhone, $flow->newPhone);
            }

            return [$flow, $this->issue($flow, $new, $ip, $now), $now];
        });

        $challenge = $this->requireChallenge($flow, $new, $challenge, $now);

        return $this->deliver($flow, $new, $challenge, $now);
    }

    private function verify(
        string $id,
        SessionIdentity|Closure $authorization,
        bool $new,
        string $challenge,
        #[\SensitiveParameter] string $code,
        Closure $clock,
    ): int {
        $result = $this->withAuthorizedFlow($id, $authorization, $clock, function (PhoneChangeFlow $flow, int $now) use (
            $new, $challenge, $code,
        ): int|BusinessException {
            $phone = $new ? $flow->newPhone : $flow->oldPhone;
            try {
                $this->verification->verify($challenge, $phone->e164, self::PURPOSE, $flow->id, $flow->binding($new), $code, $now);
            } catch (BusinessException $exception) {
                if (in_array($exception->code(), ['verification_code_invalid', 'verification_attempts_exceeded'], true)) {
                    $this->auditSms($exception->code(), $flow, $phone, $now);
                }

                return $exception;
            }
            $deadline = $now + PhoneChangeFlow::PROOF_TTL;
            $this->repository->recordProof($flow, $new, $deadline);

            return $deadline;
        });
        if ($result instanceof BusinessException) {
            throw $result;
        }

        return $result;
    }

    /** @return array{id:string, code:string, replaced:bool}|null */
    private function issue(PhoneChangeFlow $flow, bool $new, string $ip, int $now): ?array
    {
        $phone = $new ? $flow->newPhone : $flow->oldPhone;
        if (!$this->verification->reserve($phone->e164, $ip, self::PURPOSE, $now)) {
            return null;
        }

        return $this->verification->issue($phone->e164, self::PURPOSE, $flow->id, $flow->binding($new), $now);
    }

    /**
     * @param array{id:string, code:string, replaced:bool}|null $challenge
     * @return array{id:string, code:string, replaced:bool}
     */
    private function requireChallenge(PhoneChangeFlow $flow, bool $new, #[\SensitiveParameter] ?array $challenge, int $now): array
    {
        if ($challenge === null) {
            $this->auditSms('sms_rate_limited', $flow, $new ? $flow->newPhone : $flow->oldPhone, $now);
            throw new BusinessException('短信请求过于频繁', 'sms_rate_limited', 429);
        }

        return $challenge;
    }

    /** @param array{id:string, code:string, replaced:bool} $challenge */
    private function deliver(PhoneChangeFlow $flow, bool $new, #[\SensitiveParameter] array $challenge, int $now): string
    {
        $phone = $new ? $flow->newPhone : $flow->oldPhone;
        $result = $this->sender->send($phone, self::PURPOSE, $challenge['code']);
        if ($challenge['replaced']) {
            $this->auditSms('sms_challenge_reissued', $flow, $phone, $now);
        }
        if ($result === SubmissionStatus::Rejected) {
            $this->auditSms('sms_submission_failed', $flow, $phone, $now);
            throw new BusinessException('短信提交失败', 'sms_submission_failed', 502);
        }
        if ($result === SubmissionStatus::TimedOut) {
            $this->auditSms('sms_submission_timeout', $flow, $phone, $now);
            throw new BusinessException('短信提交超时', 'sms_submission_timeout', 504);
        }

        return $challenge['id'];
    }

    private function auditSms(string $action, PhoneChangeFlow $flow, MainlandMobileNumber $phone, int $now): void
    {
        try {
            $this->repository->auditSms($action, $flow, $phone, $now);
        } catch (BusinessException $exception) {
            if ($exception->code() !== 'audit_unavailable') {
                throw $exception;
            }
            error_log('Webman Starter rebind SMS audit unavailable: ' . $action);
        }
    }

    private function requireAvailable(MainlandMobileNumber $oldPhone, MainlandMobileNumber $newPhone, bool $lock = false): void
    {
        if ($oldPhone->e164 === $newPhone->e164) {
            throw BusinessException::validation(['new_phone' => ['新手机号须与当前手机号不同']]);
        }
        if ($this->repository->phoneTaken($newPhone, $lock)) {
            throw new BusinessException('该手机号已被其他账号使用', 'phone_already_used', 409);
        }
    }

    private function requireSession(SessionIdentity $identity, ?string $epoch): void
    {
        if ($epoch !== $identity->accountEpoch || ($identity->platformEpoch !== null
            && $this->repository->platformEpoch($identity->accountId) !== $identity->platformEpoch)) {
            throw BusinessException::tokenInvalid();
        }
    }

    /**
     * @param Closure():int $clock
     * @param Closure(PhoneChangeFlow, int):mixed $operation
     */
    private function withAuthorizedFlow(
        string $id,
        SessionIdentity|Closure $authorization,
        Closure $clock,
        Closure $operation,
        int $attempts = 1,
    ): mixed {
        $normal = $authorization instanceof SessionIdentity;

        return $this->repository->withFlow($id, $normal ? $authorization->accountId : null,
            function (PhoneChangeFlow $flow, string $epoch) use ($authorization, $normal, $clock, $operation): mixed {
                if ($normal !== ($flow->mode === PhoneChangeFlow::NORMAL)) {
                    throw BusinessException::notFound();
                }
                if ($normal) {
                    $this->requireSession($authorization, $epoch);
                } else {
                    $authorization($flow);
                }

                $now = $clock();
                if ($normal) {
                    $this->sessions->requireLiveIdentity($authorization, $now);
                }

                return $operation($flow, $now);
            }, $attempts);
    }
}
