<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\rebind;

use Closure;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\domain\SubmissionStatus;

interface PhoneChangeRepositoryInterface
{
    /** @return array{phone: string, phoneEpoch: string, sessionEpoch: string}|null */
    public function account(int $id): ?array;

    public function create(PhoneChangeFlow $flow, int $now, bool $oldChallengeIssued = false): void;

    /**
     * Lock and re-read the flow's account and flow, rejecting a changed source phone or epoch.
     * Retrying is safe only when the operation has no side effects outside the transaction.
     *
     * @template T
     * @param Closure(PhoneChangeFlow, string): T $operation
     * @return T
     */
    public function withFlow(string $id, ?int $accountId, Closure $operation, int $attempts = 1): mixed;

    public function platformEpoch(int $accountId): ?string;

    public function phoneTaken(MainlandMobileNumber $phone, bool $lock = false): bool;

    /** Called inside withFlow after issuance. Returns true if a challenge was already issued for this step. */
    public function recordSmsIssue(PhoneChangeFlow $flow, bool $new): bool;

    /** Called inside withFlow. */
    public function recordProof(PhoneChangeFlow $flow, bool $new, int $validUntil): void;

    /** Called inside withFlow after checking both proofs and locking the destination phone. */
    public function commitChange(PhoneChangeFlow $flow, int $now): void;

    public function auditSms(
        string $action,
        PhoneChangeFlow $flow,
        MainlandMobileNumber $phone,
        int $now,
        ?SubmissionStatus $submission = null,
    ): void;
}
