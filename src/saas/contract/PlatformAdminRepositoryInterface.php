<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\contract;

use Closure;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;

interface PlatformAdminRepositoryInterface
{
    /**
     * Run a platform qualification change under the guard in a single transaction.
     * Keep the callback free of side effects outside this connection as it may be retried.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public function withGuard(Closure $operation): mixed;

    /** Check the current state after acquiring the guard. */
    public function hasActiveAdministrator(): bool;

    /** @return array{id: int, created: bool} */
    public function findOrCreateAccount(MainlandMobileNumber $phone, string $now): array;

    /** Grant or restore a qualification while holding the guard. */
    public function grantAdministrator(int $accountId, string $now): void;

    /** @param array<string, bool|int|string|null> $redactedMetadata */
    public function appendAudit(
        string $action,
        ?int $actorAccountId,
        ?int $targetAccountId,
        array $redactedMetadata,
        string $now
    ): void;
}
