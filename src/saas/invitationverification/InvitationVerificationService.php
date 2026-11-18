<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitationverification;

use SensitiveParameter;
use Throwable;
use Z0x\WebmanStarter\saas\contract\SmsSenderInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\domain\SubmissionStatus;
use Z0x\WebmanStarter\saas\verification\RedisVerification;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

/** Callers resolve and validate the invitation before using its ID and canonical destination here. */
final class InvitationVerificationService
{
    private const PURPOSE = RedisVerification::INVITATION;
    private const FLOW = 'invitation';

    public function __construct(
        private readonly RedisVerification $verification,
        private readonly SmsSenderInterface $sender,
        private readonly InvitationVerificationAuditInterface $audit,
    ) {
    }

    /** $ip must come from a trusted connection/proxy resolver. Returns the challenge ID on submission. */
    public function send(int $invitationId, MainlandMobileNumber $phone, string $ip, int $now): string
    {
        if (!$this->verification->reserve($phone->e164, $ip, self::PURPOSE, $now)) {
            $this->record('sms_rate_limited', $invitationId, $phone, $now);
            throw new BusinessException('短信请求过于频繁', 'sms_rate_limited', 429);
        }

        $challenge = $this->verification->issue($phone->e164, self::PURPOSE, self::FLOW, (string) $invitationId, $now);
        if ($challenge['replaced']) {
            $this->record('sms_challenge_reissued', $invitationId, $phone, $now);
        }

        try {
            $result = $this->sender->send($phone, self::PURPOSE, $challenge['code']);
        } catch (Throwable) {
            // Do not log provider exceptions, which can contain the full destination and code.
            $result = SubmissionStatus::Rejected;
        }
        $this->record(match ($result) {
            SubmissionStatus::Accepted => 'sms_submitted',
            SubmissionStatus::Rejected => 'sms_submission_failed',
            SubmissionStatus::TimedOut => 'sms_submission_timeout',
        }, $invitationId, $phone, $now);

        return match ($result) {
            SubmissionStatus::Accepted => $challenge['id'],
            SubmissionStatus::Rejected => throw new BusinessException('短信提交失败', 'sms_submission_failed', 502),
            SubmissionStatus::TimedOut => throw new BusinessException('短信提交超时', 'sms_submission_timeout', 504),
        };
    }

    /** @return array{credential: string, expires_at: int} */
    public function verify(
        int $invitationId,
        MainlandMobileNumber $phone,
        string $challengeId,
        #[SensitiveParameter] string $code,
        int $now,
    ): array {
        try {
            return $this->verification->verifyAndIssueProof(
                $challengeId, $phone->e164, self::PURPOSE, self::FLOW, (string) $invitationId, $code, $now,
            );
        } catch (BusinessException $exception) {
            if (in_array($exception->code(), ['verification_code_invalid', 'verification_attempts_exceeded'], true)) {
                $this->record($exception->code(), $invitationId, $phone, $now);
            }
            throw $exception;
        }
    }

    /**
     * Check the proof with the current clock while holding the authoritative invitation lock.
     * Acceptance must recheck the invitation's lifecycle and commit its consumption in the same
     * SQL transaction. A rollback leaves this proof retryable. This check never extends its expiry.
     */
    public function requireCredential(
        int $invitationId,
        MainlandMobileNumber $phone,
        #[SensitiveParameter] string $credential,
        int $now,
    ): void {
        $this->verification->requireProof($phone->e164, self::PURPOSE, self::FLOW, (string) $invitationId, $credential, $now);
    }

    /** Optional idempotent cleanup after SQL commit. SQL state invalidates every proof for a terminal invitation. */
    public function forgetCredential(
        int $invitationId,
        MainlandMobileNumber $phone,
        #[SensitiveParameter] string $credential,
    ): void {
        $this->verification->forgetProof($phone->e164, self::PURPOSE, self::FLOW, (string) $invitationId, $credential);
    }

    private function record(string $action, int $invitationId, MainlandMobileNumber $phone, int $now): void
    {
        try {
            $this->audit->record($action, $invitationId, $phone->masked(), $now);
        } catch (BusinessException $exception) {
            if ($exception->code() !== 'audit_unavailable') {
                throw $exception;
            }
            error_log('Webman Starter invitation SMS audit unavailable: ' . $action);
        }
    }
}
