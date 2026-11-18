<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\contract;

interface OrganizationAuditInterface
{
    /** @param array<string, bool|int|string|null> $redactedMetadata */
    public function append(
        int $organizationId,
        string $action,
        ?int $actorAccountId,
        ?int $targetAccountId,
        array $redactedMetadata,
        int $now,
    ): void;
}
