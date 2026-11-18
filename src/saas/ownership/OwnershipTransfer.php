<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\ownership;

final class OwnershipTransfer
{
    public const LIFETIME = 7 * 86400;

    public function __construct(
        public readonly int $id,
        public readonly int $organizationId,
        public readonly int $proposerAccountId,
        public readonly int $recipientAccountId,
        public readonly int $expiresAt,
        public readonly string $status,
    ) {
    }

    public function isPendingAt(int $now): bool
    {
        return $this->status === 'pending' && $now < $this->expiresAt;
    }
}
