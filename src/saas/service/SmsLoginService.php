<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\service;

use Z0x\WebmanStarter\saas\contract\SmsLoginRepositoryInterface;
use Z0x\WebmanStarter\saas\contract\SmsSenderInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\domain\SubmissionStatus;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\saas\verification\RedisVerification;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class SmsLoginService
{
    private const PURPOSE = 'login';
    private const FLOW = 'login';

    public function __construct(
        private readonly SmsLoginRepositoryInterface $repository,
        private readonly RedisVerification $verification,
        private readonly SmsSenderInterface $sender,
        private readonly SessionService $sessions,
    ) {
    }

    public function send(MainlandMobileNumber $phone, string $ip, int $now): string
    {
        if (!$this->verification->reserve($phone->e164, $ip, self::PURPOSE, $now)) {
            $this->record('sms_rate_limited', $phone, null, $now);
            throw new BusinessException('短信请求过于频繁', 'sms_rate_limited', 429);
        }

        $account = $this->repository->account($phone);
        $eligible = $account !== null || $this->repository->hasValidInvitation($phone, $now);

        try {
            $challenge = $this->verification->issue($phone->e164, self::PURPOSE, self::FLOW, self::binding($account), $now);
        } catch (BusinessException $exception) {
            if ($exception->code() !== 'credential_expired') {
                throw $exception;
            }

            return bin2hex(random_bytes(16));
        }
        if (!$eligible) {
            // Keep guessed-code responses and attempt limits consistent for skipped submissions.
            return $challenge['id'];
        }
        try {
            $result = $this->sender->send($phone, self::PURPOSE, $challenge['code']);
        } catch (\Throwable) {
            $result = SubmissionStatus::Rejected;
        }
        if ($challenge['replaced']) {
            $this->record('sms_challenge_reissued', $phone, $account['id'] ?? null, $now);
        }
        if ($result === SubmissionStatus::Rejected) {
            $this->record('sms_submission_failed', $phone, $account['id'] ?? null, $now);
        } elseif ($result === SubmissionStatus::TimedOut) {
            $this->record('sms_submission_timeout', $phone, $account['id'] ?? null, $now);
        }

        return $challenge['id'];
    }

    public function login(MainlandMobileNumber $phone, string $id, string $code, string $purpose, int $now): array
    {
        try {
            return $this->repository->withPhoneAccount($phone, function (?array $account) use ($phone, $id, $code, $purpose, $now): array {
                $this->verification->verify($id, $phone->e164, self::PURPOSE, self::FLOW, self::binding($account), $code, $now);
                if ($account === null) {
                    throw BusinessException::unauthenticated();
                }

                return $this->sessions->issue($account['id'], $purpose, $now);
            });
        } catch (BusinessException $exception) {
            if (in_array($exception->code(), ['verification_code_invalid', 'verification_attempts_exceeded'], true)) {
                $this->record($exception->code(), $phone, null, $now);
            }
            throw $exception;
        }
    }

    /** @param array{id:int,phone_epoch:int}|null $account */
    private static function binding(?array $account): string
    {
        return $account === null ? 'invited' : $account['id'] . ':' . $account['phone_epoch'];
    }

    private function record(string $action, MainlandMobileNumber $phone, ?int $accountId, int $now): void
    {
        try {
            $this->repository->audit($action, $phone, $accountId, $now);
        } catch (BusinessException $exception) {
            if ($exception->code() !== 'audit_unavailable') {
                throw $exception;
            }
            // Keep the public response independent of provider and account state.
            error_log('Webman Starter SMS audit unavailable: ' . $action);
        }
    }
}
