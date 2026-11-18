<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\emailchange;

use Z0x\WebmanStarter\saas\email\EmailAddress;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class EmailChangeFlow
{
    public const PROOF_TTL = 600;

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
        self::requireProof($this->sourceVerifiedUntil, $now);
    }

    public function requireNewProof(int $now): void
    {
        self::requireProof($this->newVerifiedUntil, $now);
    }

    private static function requireProof(?int $deadline, int $now): void
    {
        if ($deadline === null || $deadline <= $now) {
            throw new BusinessException('验证凭据已过期或尚未取得', 'credential_expired', 401);
        }
    }
}
