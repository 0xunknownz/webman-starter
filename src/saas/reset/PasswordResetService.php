<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\reset;

use Throwable;
use Z0x\WebmanStarter\saas\domain\SubmissionStatus;
use Z0x\WebmanStarter\saas\email\EmailAddress;
use Z0x\WebmanStarter\saas\email\EmailSenderInterface;
use Z0x\WebmanStarter\saas\email\EmailVerification;
use Z0x\WebmanStarter\saas\email\Password;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class PasswordResetService
{
    private const SKIPPED_FLOW = 'skipped';

    public function __construct(
        private readonly PasswordResetRepositoryInterface $repository,
        private readonly EmailVerification $verification,
        private readonly EmailSenderInterface $sender,
    ) {
    }

    public function send(EmailAddress $email, string $ip, int $now): string
    {
        $purpose = EmailVerification::RESET;
        if (!$this->verification->reserve($email->value, $ip, $purpose, $now)) {
            $this->auditAttempt('email_rate_limited', null, $now);
            throw new BusinessException('邮件请求过于频繁', 'email_rate_limited', 429);
        }

        $account = $this->repository->account($email);
        $hash = self::verifiedPasswordHash($account, $email);
        $accountId = $hash === null ? null : $account['id'];
        $flow = $accountId === null ? self::SKIPPED_FLOW : (string) $accountId;
        $binding = $hash === null ? '' : self::binding($account['email_epoch'], $hash);

        try {
            $challenge = $this->verification->issue($email->value, $purpose, $flow, $binding, $now);
        } catch (BusinessException $exception) {
            if ($exception->code() !== 'credential_expired') {
                throw $exception;
            }

            return bin2hex(random_bytes(16));
        }

        if ($accountId === null) {
            return $challenge['id'];
        }

        try {
            $status = $this->sender->send($email->value, $challenge['code'], $purpose);
        } catch (Throwable) {
            // A custom supplier's exception must not distinguish an eligible account or expose transport details.
            $status = SubmissionStatus::Rejected;
        }
        $action = match ($status) {
            SubmissionStatus::Accepted => 'email_submission_accepted',
            SubmissionStatus::Rejected => 'email_submission_failed',
            SubmissionStatus::TimedOut => 'email_submission_timeout',
        };
        $this->auditAttempt($action, $accountId, $now);

        return $challenge['id'];
    }

    public function complete(
        EmailAddress $email,
        string $id,
        #[\SensitiveParameter] string $code,
        #[\SensitiveParameter] mixed $password,
        int $now,
    ): void {
        if (!Password::isValid($password)) {
            throw BusinessException::validation(['password' => ['密码格式无效']]);
        }

        $account = $this->repository->account($email);
        $accountId = self::verifiedPasswordHash($account, $email) === null ? null : $account['id'];
        try {
            if ($accountId === null) {
                // Skipped sends share ordinary proof failures. Even a correct guess cannot authorize a reset.
                $this->verification->verify($id, $email->value, EmailVerification::RESET, self::SKIPPED_FLOW, '', $code, $now);
                throw new BusinessException('Verification code is invalid.', 'verification_code_invalid', 401);
            }
            $this->repository->withLockedAccount($accountId, function (#[\SensitiveParameter] ?array $current) use ($accountId, $email, $id, $code, $password, $now): void {
                // The unlocked lookup identifies the row. Verify the current email binding and hash under its lock.
                $hash = self::verifiedPasswordHash($current, $email);
                if ($hash === null) {
                    throw self::expired();
                }
                $this->verification->verify($id, $email->value, EmailVerification::RESET, (string) $accountId,
                    self::binding($current['email_epoch'], $hash), $code, $now);
                $this->repository->reset($accountId, Password::hash($password), $now);
            });
        } catch (BusinessException $exception) {
            if (in_array($exception->code(), ['verification_code_invalid', 'verification_attempts_exceeded'], true)) {
                $this->auditAttempt($exception->code(), $accountId, $now);
            }
            throw $exception;
        }
    }

    /** @param array{email:?string,email_verified_at:?string,password_hash:?string}|null $account */
    private static function verifiedPasswordHash(#[\SensitiveParameter] ?array $account, EmailAddress $email): ?string
    {
        if (($account['email'] ?? null) !== $email->value || $account['email_verified_at'] === null) {
            return null;
        }

        return $account['password_hash'];
    }

    private static function binding(string $epoch, #[\SensitiveParameter] string $hash): string
    {
        return $epoch . ':' . hash('sha256', $hash);
    }

    private function auditAttempt(string $action, ?int $accountId, int $now): void
    {
        try {
            $this->repository->auditAttempt($action, $accountId, $now);
        } catch (BusinessException $exception) {
            if ($exception->code() !== 'audit_unavailable') {
                throw $exception;
            }
            // The reset itself requires its transactional audit. Failure to audit an attempt must preserve its response.
            error_log('Webman Starter password reset audit unavailable: ' . $action);
        }
    }

    private static function expired(): BusinessException
    {
        return new BusinessException('Verification challenge has expired or is no longer available.', 'credential_expired', 401);
    }
}
