<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\audit;

interface AuditLogRepositoryInterface
{
    /** Null organization selects platform-visible events. */
    public function count(?int $organizationId, ?string $action): int;

    /** @return iterable<object> Ordered by newest timestamp, then descending ID. */
    public function read(?int $organizationId, ?string $action, int $offset = 0, ?int $limit = null): iterable;
}
