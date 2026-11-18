<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\recovery;

use Closure;
use Throwable;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\domain\SubmissionStatus;
use Z0x\WebmanStarter\saas\email\EmailAddress;
use Z0x\WebmanStarter\saas\email\EmailSenderInterface;
use Z0x\WebmanStarter\saas\email\EmailVerification;
use Z0x\WebmanStarter\saas\rebind\PhoneChangeFlow;
use Z0x\WebmanStarter\saas\rebind\PhoneChangeRepositoryInterface;
use Z0x\WebmanStarter\saas\rebind\PhoneChangeService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class EmailRecoveryService
{
    private const MODE = 'email_recovery';
    private const PURPOSE = EmailVerification::RECOVERY;
    private const SKIPPED_FLOW = 'skipped';

    /** Both repositories and PhoneChangeService must use the same database connection. */
    public function __construct(
        private readonly EmailRecoveryRepositoryInterface $repository,
        private readonly PhoneChangeRepositoryInterface $flows,
        private readonly PhoneChangeService $phoneChanges,
        private readonly EmailVerification $verification,
        private readonly EmailSenderInterface $sender,
    ) {
    }

    public function send(EmailAddress $email, string $ip, int $now): string
    {
        if (!$this->verification->reserve($email->value, $ip, self::PURPOSE, $now)) {
            $this->auditAttempt('email_rate_limited', null, $now);
            throw new BusinessException('邮件请求过于频繁', 'email_rate_limited', 429);
        }

        $account = $this->repository->account($email);
        $eligible = self::verified($account, $email);
        $scope = $eligible ? (string) $account['id'] : self::SKIPPED_FLOW;
        $binding = $eligible ? self::binding($account) : '';
        try {
            $challenge = $this->verification->issue($email->value, self::PURPOSE, $scope, $binding, $now);
        } catch (BusinessException $exception) {
            if ($exception->code() !== 'credential_expired') {
                throw $exception;
            }

            return bin2hex(random_bytes(16));
        }
        if (!$eligible) {
            return $challenge['id'];
        }

        try {
            $status = $this->sender->send($email->value, $challenge['code'], self::PURPOSE);
        } catch (Throwable) {
            $status = SubmissionStatus::Rejected;
        }
        $action = match ($status) {
            SubmissionStatus::Accepted => 'email_submission_accepted',
            SubmissionStatus::Rejected => 'email_submission_failed',
            SubmissionStatus::TimedOut => 'email_submission_timeout',
        };
        $this->auditAttempt($action, $account['id'], $now);

        return $challenge['id'];
    }

    /**
     * @param Closure():int $clock
     * @return array{flow_id:string,recovery_token:string,verified_until:int}
     */
    public function verify(
        EmailAddress $email,
        string $challenge,
        #[\SensitiveParameter] string $code,
        MainlandMobileNumber $newPhone,
        Closure $clock,
    ): array {
        $account = $this->repository->account($email);
        $accountId = self::verified($account, $email) ? $account['id'] : null;
        try {
            if ($accountId === null) {
                $this->verification->verify($challenge, $email->value, self::PURPOSE, self::SKIPPED_FLOW, '', $code, $clock());
                // Even a correct guess against a decoy challenge cannot create a recovery flow.
                throw new BusinessException('Verification code is invalid.', 'verification_code_invalid', 401);
            }

            return $this->repository->withLockedAccount($account['phone'], function (#[\SensitiveParameter] ?array $current) use (
                $accountId, $email, $challenge, $code, $newPhone, $clock,
            ): array {
                if (!self::verified($current, $email) || $current['id'] !== $accountId) {
                    throw self::expired();
                }
                $now = $clock();
                $this->verification->verify($challenge, $email->value, self::PURPOSE, (string) $accountId,
                    self::binding($current), $code, $now);
                $oldPhone = MainlandMobileNumber::fromInput($current['phone']);
                if ($newPhone->e164 === $oldPhone->e164) {
                    throw BusinessException::validation(['new_phone' => ['新手机号须与当前手机号不同']]);
                }
                if ($this->flows->phoneTaken($newPhone)) {
                    throw new BusinessException('该手机号已被其他账号使用', 'phone_already_used', 409);
                }

                $token = bin2hex(random_bytes(32));
                $until = $now + PhoneChangeFlow::PROOF_TTL;
                $flow = new PhoneChangeFlow(
                    bin2hex(random_bytes(16)), $accountId, $oldPhone, $current['phone_epoch'], $newPhone,
                    self::MODE, $until, null, $accountId,
                );
                $this->flows->create($flow, $now);
                $this->repository->storeProof($flow, $current['email_epoch'], self::digest($flow, $current['email_epoch'], $token));

                return ['flow_id' => $flow->id, 'recovery_token' => $token, 'verified_until' => $until];
            });
        } catch (BusinessException $exception) {
            if (in_array($exception->code(), ['verification_code_invalid', 'verification_attempts_exceeded'], true)) {
                $this->auditAttempt($exception->code(), $accountId, $clock());
            }
            throw $exception;
        }
    }

    /** @param Closure():int $clock */
    public function sendNew(string $flow, #[\SensitiveParameter] string $token, string $ip, Closure $clock): string
    {
        return $this->phoneChanges->sendNew($flow, $this->authorize($token, $clock), $ip, $clock);
    }

    /** @param Closure():int $clock */
    public function verifyNew(
        string $flow,
        #[\SensitiveParameter] string $token,
        string $challenge,
        #[\SensitiveParameter] string $code,
        Closure $clock,
    ): int {
        return $this->phoneChanges->verifyNew($flow, $this->authorize($token, $clock), $challenge, $code, $clock);
    }

    /** @param Closure():int $clock */
    public function complete(string $flow, #[\SensitiveParameter] string $token, Closure $clock): void
    {
        $this->phoneChanges->complete($flow, $this->authorize($token, $clock), $clock);
    }

    /**
     * PhoneChangeService runs this read-only closure under the shared account/flow locks, including on retry.
     * Other recovery modes must supply their own authorizer and check their own mode and actor.
     *
     * @param Closure():int $clock
     * @return Closure(PhoneChangeFlow):void
     */
    private function authorize(#[\SensitiveParameter] string $token, Closure $clock): Closure
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            throw self::expired();
        }

        return function (PhoneChangeFlow $flow) use ($token, $clock): void {
            if ($flow->mode !== self::MODE || $flow->actorAccountId !== $flow->accountId) {
                throw BusinessException::notFound();
            }
            $proof = $this->repository->lockedProof($flow);
            if ($proof === null || $proof['current_email_epoch'] !== $proof['email_epoch']
                || !hash_equals($proof['token_hash'], self::digest($flow, $proof['email_epoch'], $token))) {
                throw self::expired();
            }
            $flow->requireSourceProof($clock());
        };
    }

    private static function verified(#[\SensitiveParameter] ?array $account, EmailAddress $email): bool
    {
        return ($account['email'] ?? null) === $email->value && $account['email_verified_at'] !== null;
    }

    private static function binding(#[\SensitiveParameter] array $account): string
    {
        return implode(':', [$account['email_epoch'], $account['phone_epoch'], $account['phone']]);
    }

    private static function digest(PhoneChangeFlow $flow, string $emailEpoch, #[\SensitiveParameter] string $token): string
    {
        return hash('sha256', implode("\0", [self::MODE, $flow->id, $flow->binding(true), $emailEpoch, $token]));
    }

    private function auditAttempt(string $action, ?int $accountId, int $now): void
    {
        try {
            $this->repository->auditAttempt($action, $accountId, $now);
        } catch (BusinessException $exception) {
            if ($exception->code() !== 'audit_unavailable') {
                throw $exception;
            }
            error_log('Webman Starter email recovery attempt audit unavailable: ' . $action);
        }
    }

    private static function expired(): BusinessException
    {
        return new BusinessException('验证凭据已过期或尚未取得', 'credential_expired', 401);
    }
}
