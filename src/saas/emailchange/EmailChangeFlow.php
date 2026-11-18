<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\emailchange;

use Z0x\WebmanStarter\saas\domain\VerificationProof;
use Z0x\WebmanStarter\saas\email\EmailAddress;

final class EmailChangeFlow
{
    public const PROOF_TTL = VerificationProof::TTL;

    public function __construct(
        public readonly string $id,
        public readonly int $accountId,
        public readonly string $sessionId,
        public readonly string $sessionEpoch,
        public readonly string $emailEpoch,
        public readonly string $phoneEpoch,
        public readonly EmailAddress $newEmail,
        public readonly string $channel,
        public readonly ?int $sourceVerifiedUntil = null,
        public readonly ?int $newVerifiedUntil = null,
    ) {
    }

    public function binding(bool $new): string
    {
        return implode(':', [
            $new ? 'new' : 'old', $this->accountId, $this->sessionId, $this->sessionEpoch,
            $this->emailEpoch, $this->phoneEpoch, $this->channel, $this->newEmail->value,
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
