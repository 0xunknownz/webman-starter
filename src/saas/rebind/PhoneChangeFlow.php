<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\rebind;

use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\domain\VerificationProof;

final class PhoneChangeFlow
{
    public const NORMAL = 'normal';
    public const MANUAL_RECOVERY = 'manual_recovery';
    public const OFFLINE_RECOVERY = 'offline_recovery';
    public const PROOF_TTL = VerificationProof::TTL;

    public function __construct(
        public readonly string $id,
        public readonly int $accountId,
        public readonly MainlandMobileNumber $oldPhone,
        public readonly string $oldPhoneEpoch,
        public readonly MainlandMobileNumber $newPhone,
        public readonly string $mode,
        public readonly ?int $sourceVerifiedUntil,
        public readonly ?int $newVerifiedUntil,
        public readonly ?int $actorAccountId,
        public readonly ?string $actorRef = null,
        public readonly ?string $reasonRef = null,
        public readonly ?string $recoveryReason = null,
        public readonly ?string $evidenceChannel = null,
    ) {
    }

    public function binding(bool $new): string
    {
        return implode(':', [
            $new ? 'new' : 'old', $this->accountId, $this->oldPhoneEpoch,
            $this->oldPhone->e164, $this->newPhone->e164, $this->mode,
        ]);
    }

    public function requireSourceProof(int $now): void
    {
        VerificationProof::requireCurrent($this->sourceVerifiedUntil, $now);
    }

    public function requireNewProof(int $now): void
    {
        VerificationProof::requireCurrent($this->newVerifiedUntil, $now);
    }
}
