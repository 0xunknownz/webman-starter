<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\platform;

use Closure;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;

interface PlatformAdminMutationRepositoryInterface
{
    /** Execute a transaction holding the platform guard. */
    public function withGuard(Closure $operation): mixed;

    /** @return array{actor_epoch: ?string, target_id: int, created: bool} */
    public function lockGrantAccounts(int $actorId, MainlandMobileNumber $phone, string $now): array;

    public function lockAccountEpoch(int $actorId, ?int $targetId): ?string;

    /** @return array<int, array{status: string, epoch: string}> */
    public function lockAdministrators(int $actorId, int $targetId): array;

    public function hasOtherActiveAdministrator(int $targetId): bool;

    public function grantAdministrator(int $targetId, string $now): void;

    public function revokeAdministrator(int $targetId, string $now): void;

    /** @param array<string, bool|string> $redactedMetadata */
    public function appendAudit(string $action, int $actorId, int $targetId, array $redactedMetadata, string $now): void;
}
