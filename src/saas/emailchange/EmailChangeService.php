<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\emailchange;

use Closure;
use Z0x\WebmanStarter\saas\contract\SmsSenderInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\domain\SubmissionStatus;
use Z0x\WebmanStarter\saas\email\EmailAddress;
use Z0x\WebmanStarter\saas\email\EmailSenderInterface;
use Z0x\WebmanStarter\saas\email\EmailVerification;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\saas\verification\RedisVerification;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class EmailChangeService
{
    public function __construct(
        private readonly EmailChangeRepositoryInterface $repository,
        private readonly EmailVerification $emailVerification,
        private readonly RedisVerification $smsVerification,
        private readonly EmailSenderInterface $emailSender,
        private readonly SmsSenderInterface $smsSender,
        private readonly SessionService $sessions,
    ) {
    }

    /**
     * @param Closure():int $clock
     * @return array{flow_id:string,challenge_id:string}
     */
    public function start(SessionIdentity $identity, EmailAddress $newEmail, string $channel, string $ip, Closure $clock): array
    {
        if ($channel !== 'email' && $channel !== 'sms') {
            throw BusinessException::validation(['channel' => ['请选择 email 或 sms']]);
        }

        [$flow, $target, $challenge, $now] = $this->repository->withAccount($identity, function (array $account) use (
            $identity, $newEmail, $channel, $ip, $clock,
        ): array {
            if ($account['email'] === null || !$account['emailVerified']) {
                throw BusinessException::forbidden();
            }
            if ($account['email'] === $newEmail->value) {
                throw BusinessException::validation(['new_email' => ['新邮箱须与当前邮箱不同']]);
            }
            $this->requireAvailable($newEmail);

            $flow = new EmailChangeFlow(
                bin2hex(random_bytes(16)), $identity->accountId, $identity->sessionId, $identity->accountEpoch,
                $account['emailEpoch'], $account['phoneEpoch'], $newEmail, $channel,
            );
            $target = $channel === 'sms' ? $account['phone'] : $account['email'];
            $now = $clock();
            $this->sessions->requireLiveIdentity($identity, $now);
            $challenge = $this->issue($flow, false, $channel, $target, $ip, $now);
            if ($challenge !== null) {
                $this->repository->create($flow, $now);
            }

            return [$flow, $target, $challenge, $now];
        });

        return [
            'flow_id' => $flow->id,
            'challenge_id' => $this->deliver($flow->accountId, $channel, $target, $challenge, $now),
        ];
    }

    /** @param Closure():int $clock */
    public function send(string $id, SessionIdentity $identity, bool $new, string $ip, Closure $clock): string
    {
        [$channel, $target, $challenge, $now] = $this->withFlow($id, $identity, function (EmailChangeFlow $flow, array $account) use (
            $identity, $new, $ip, $clock,
        ): array {
            $now = $clock();
            $this->sessions->requireLiveIdentity($identity, $now);
            if ($new) {
                $flow->requireSourceProof($now);
                $this->requireAvailable($flow->newEmail);
            }
            [$channel, $target] = self::recipient($flow, $account, $new);

            return [$channel, $target, $this->issue($flow, $new, $channel, $target, $ip, $now), $now];
        });

        return $this->deliver($identity->accountId, $channel, $target, $challenge, $now);
    }

    /** @param Closure():int $clock */
    public function verify(
        string $id,
        SessionIdentity $identity,
        bool $new,
        string $challenge,
        #[\SensitiveParameter] string $code,
        Closure $clock,
    ): int {
        $result = $this->withFlow($id, $identity, function (EmailChangeFlow $flow, array $account) use (
            $identity, $new, $challenge, $code, $clock,
        ): int|BusinessException {
            $now = $clock();
            $this->sessions->requireLiveIdentity($identity, $now);
            if ($new) {
                $flow->requireSourceProof($now);
            }
            [$channel, $target] = self::recipient($flow, $account, $new);
            try {
                $this->verification($channel)->verify($challenge, $target, self::purpose($channel), $flow->id,
                    $flow->binding($new), $code, $now);
            } catch (BusinessException $exception) {
                if (in_array($exception->code(), ['verification_code_invalid', 'verification_attempts_exceeded'], true)) {
                    $this->auditAttempt($exception->code(), $flow->accountId, $channel, $now);
                }

                return $exception;
            }

            $now = $clock();
            if ($new) {
                $flow->requireSourceProof($now);
            }
            $deadline = $now + EmailChangeFlow::PROOF_TTL;
            $this->repository->recordProof($flow, $new, $deadline);

            return $deadline;
        });
        if ($result instanceof BusinessException) {
            throw $result;
        }

        return $result;
    }

    /** @param Closure():int $clock */
    public function complete(string $id, SessionIdentity $identity, Closure $clock): void
    {
        $this->withFlow($id, $identity, function (EmailChangeFlow $flow) use ($identity, $clock): void {
            $now = $clock();
            $this->sessions->requireLiveIdentity($identity, $now);
            $flow->requireSourceProof($now);
            $flow->requireNewProof($now);
            $this->requireAvailable($flow->newEmail, lock: true);
            // The destination lock can wait behind another account.
            $now = $clock();
            $this->sessions->requireLiveIdentity($identity, $now);
            $flow->requireSourceProof($now);
            $flow->requireNewProof($now);
            $this->repository->commitChange($flow, $identity, $now);
        }, attempts: 3);
    }

    /** @return array{id:string,code:string,replaced:bool}|null */
    private function issue(EmailChangeFlow $flow, bool $new, string $channel, string $target, string $ip, int $now): ?array
    {
        $verification = $this->verification($channel);
        $purpose = self::purpose($channel);
        if (!$verification->reserve($target, $ip, $purpose, $now)) {
            return null;
        }

        return $verification->issue($target, $purpose, $flow->id, $flow->binding($new), $now);
    }

    /** @param array{id:string,code:string,replaced:bool}|null $challenge */
    private function deliver(
        int $accountId,
        string $channel,
        string $target,
        #[\SensitiveParameter] ?array $challenge,
        int $now,
    ): string {
        $label = $channel === 'sms' ? '短信' : '邮件';
        if ($challenge === null) {
            $this->auditAttempt($channel . '_rate_limited', $accountId, $channel, $now);
            throw new BusinessException($label . '请求过于频繁', $channel . '_rate_limited', 429);
        }

        $purpose = self::purpose($channel);
        $result = $channel === 'sms'
            ? $this->smsSender->send(MainlandMobileNumber::fromInput($target), $purpose, $challenge['code'])
            : $this->emailSender->send($target, $challenge['code'], $purpose);
        if ($challenge['replaced']) {
            $this->auditAttempt($channel . '_challenge_reissued', $accountId, $channel, $now);
        }
        if ($result === SubmissionStatus::Rejected) {
            $this->auditAttempt($channel . '_submission_failed', $accountId, $channel, $now);
            throw new BusinessException($label . '提交失败', $channel . '_submission_failed', 502);
        }
        if ($result === SubmissionStatus::TimedOut) {
            $this->auditAttempt($channel . '_submission_timeout', $accountId, $channel, $now);
            throw new BusinessException($label . '提交超时', $channel . '_submission_timeout', 504);
        }

        return $challenge['id'];
    }

    private function auditAttempt(string $action, int $accountId, string $channel, int $now): void
    {
        try {
            $this->repository->auditAttempt($action, $accountId, self::purpose($channel), $channel, $now);
        } catch (BusinessException $exception) {
            if ($exception->code() !== 'audit_unavailable') {
                throw $exception;
            }
            error_log('Webman Starter email change audit unavailable: ' . $action);
        }
    }

    private function requireAvailable(EmailAddress $newEmail, bool $lock = false): void
    {
        if ($this->repository->emailTaken($newEmail, $lock)) {
            throw new BusinessException('邮箱已被其他账号使用', 'email_already_used', 409);
        }
    }

    private function withFlow(string $id, SessionIdentity $identity, Closure $operation, int $attempts = 1): mixed
    {
        return $this->repository->withAccount($identity, function (array $account) use ($id, $identity, $operation): mixed {
            $flow = $this->repository->lockFlow($id, $identity);
            if ($flow->sessionEpoch !== $identity->accountEpoch || !$account['emailVerified'] || $account['email'] === null
                || $flow->emailEpoch !== $account['emailEpoch'] || $flow->phoneEpoch !== $account['phoneEpoch']) {
                throw new BusinessException('验证流程已失效', 'credential_expired', 401);
            }

            return $operation($flow, $account);
        }, $attempts);
    }

    /**
     * @param array{email:string,phone:string} $account
     * @return array{string,string}
     */
    private static function recipient(EmailChangeFlow $flow, array $account, bool $new): array
    {
        if ($new) {
            return ['email', $flow->newEmail->value];
        }

        return [$flow->channel, $flow->channel === 'sms' ? $account['phone'] : $account['email']];
    }

    private function verification(string $channel): EmailVerification|RedisVerification
    {
        return $channel === 'sms' ? $this->smsVerification : $this->emailVerification;
    }

    private static function purpose(string $channel): string
    {
        return $channel === 'sms' ? RedisVerification::ACCOUNT_SECURITY : EmailVerification::CHANGE;
    }
}
