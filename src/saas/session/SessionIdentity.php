<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\session;

final class SessionIdentity
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $sessionId,
        public readonly string $purpose,
        public readonly string $accountEpoch,
        public readonly ?string $platformEpoch,
        public readonly string $issuedAccountEpoch,
    ) {
    }
}
