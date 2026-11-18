<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\email;

use Z0x\WebmanStarter\saas\domain\SubmissionStatus;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class EmailSignInService
{
    public function __construct(
        private readonly EmailAccountRepositoryInterface $repository,
        private readonly EmailVerification $verification,
        private readonly EmailSenderInterface $sender,
        private readonly RedisPasswordLoginLimiter $loginLimiter,
        private readonly SessionService $sessions,
    ) {
    }

    public function send(int $accountId, EmailAddress $email, string $ip, int $now): string
    {
        $account = $this->repository->account($accountId);
        if ($account === null) {
            throw BusinessException::tokenInvalid();
        }
        if ($account['email'] !== null) {
            throw BusinessException::forbidden();
        }
        if ($this->repository->emailOwner($email) !== null) {
            throw new BusinessException('邮箱已被其他账号使用', 'email_already_used', 409);
        }

        $purpose = EmailVerification::BIND;
        if (!$this->verification->reserve($email->value, $ip, $purpose, $now)) {
            $this->auditAttempt('email_rate_limited', $accountId, $now);
            throw new BusinessException('邮件请求过于频繁', 'email_rate_limited', 429);
        }
        $challenge = $this->verification->issue($email->value, $purpose, (string) $accountId, (string) $account['email_epoch'], $now);
        $status = $this->sender->send($email->value, $challenge['code'], $purpose);
        if ($challenge['replaced']) {
            $this->auditAttempt('email_challenge_reissued', $accountId, $now);
        }
        if ($status === SubmissionStatus::Rejected) {
            $this->auditAttempt('email_submission_failed', $accountId, $now);
            throw new BusinessException('邮件提交失败', 'email_submission_failed', 502);
        }
        if ($status === SubmissionStatus::TimedOut) {
            $this->auditAttempt('email_submission_timeout', $accountId, $now);
            throw new BusinessException('邮件提交超时', 'email_submission_timeout', 504);
        }

        return $challenge['id'];
    }

    public function bind(SessionIdentity $identity, EmailAddress $email, string $id, #[\SensitiveParameter] string $code, int $now): void
    {
        $accountId = $identity->accountId;
        try {
            $this->sessions->withCurrentIdentity($identity, $now, function () use ($accountId, $email, $id, $code, $now): void {
                $account = $this->repository->account($accountId);
                if ($account === null) {
                    throw BusinessException::tokenInvalid();
                }
                $this->verification->verify($id, $email->value, EmailVerification::BIND, (string) $accountId,
                    (string) $account['email_epoch'], $code, $now);
                if ($account['email'] !== null) {
                    throw BusinessException::forbidden();
                }
                $this->repository->bind($accountId, $email, $now);
            });
        } catch (BusinessException $exception) {
            if (in_array($exception->code(), ['verification_code_invalid', 'verification_attempts_exceeded'], true)) {
                $this->auditAttempt($exception->code(), $accountId, $now);
            }
            throw $exception;
        }
    }

    public function setPassword(SessionIdentity $identity, #[\SensitiveParameter] mixed $password, int $now): void
    {
        $hash = Password::hash($password);
        $accountId = $identity->accountId;
        $this->sessions->withCurrentIdentity($identity, $now, function () use ($accountId, $hash, $now): void {
            $account = $this->repository->account($accountId);
            if ($account === null) {
                throw BusinessException::tokenInvalid();
            }
            if ($account['email'] === null || $account['email_verified_at'] === null) {
                throw BusinessException::forbidden();
            }
            $this->repository->setPasswordHash($accountId, $hash, $now);
        });
    }

    /** @return array<string,mixed> */
    public function login(EmailAddress $email, #[\SensitiveParameter] mixed $password, string $purpose, int $now): array
    {
        $accountId = $this->repository->emailOwner($email);
        if ($accountId === null) {
            Password::verify($password, null);
            throw self::invalidCredentials();
        }

        return $this->repository->withLockedAccount($accountId, function (?array $account) use ($email, $password, $purpose, $now, $accountId): array {
            // Re-read the current binding and hash under the same account lock used by credential changes.
            if ($account === null || $account['email'] !== $email->value || $account['email_verified_at'] === null
                || $account['password_hash'] === null) {
                Password::verify($password, null);
                throw self::invalidCredentials();
            }
            if ($this->loginLimiter->locked($accountId, $now)) {
                throw self::locked();
            }
            $valid = Password::verify($password, $account['password_hash']);
            if ($this->loginLimiter->record($accountId, $valid, $now)) {
                throw self::locked();
            }
            if (!$valid) {
                throw self::invalidCredentials();
            }

            return $this->sessions->issue($accountId, $purpose, $now);
        });
    }

    private function auditAttempt(string $action, int $accountId, int $now): void
    {
        try {
            $this->repository->audit($action, $accountId, ['purpose' => EmailVerification::BIND], $now);
        } catch (BusinessException $exception) {
            if ($exception->code() !== 'audit_unavailable') {
                throw $exception;
            }
            error_log('Webman Starter email audit unavailable: ' . $action);
        }
    }

    private static function invalidCredentials(): BusinessException
    {
        return new BusinessException('邮箱或密码错误', 'credentials_invalid', 401);
    }

    private static function locked(): BusinessException
    {
        return new BusinessException('邮箱密码登录暂时锁定', 'password_login_locked', 429);
    }
}
