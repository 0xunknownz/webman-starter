<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organization;

final class Organization
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $activated,
        public readonly bool $suspended,
        public readonly bool $archived,
        public readonly SubscriptionWindow $subscription,
        public readonly ?int $packageId,
        public readonly ?string $packageName,
        public readonly ?int $seatLimit,
    ) {
    }
}
