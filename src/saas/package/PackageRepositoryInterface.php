<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\package;

use Closure;

interface PackageRepositoryInterface
{
    /** @template T @param Closure():T $operation @return T */
    public function transaction(Closure $operation): mixed;

    /** Lock the template in the caller's transaction, or throw if absent. */
    public function lock(int $packageId): PackageTemplate;

    /** Return the validated snapshot of a usable template under the caller's transaction lock. */
    public function lockAvailable(int $packageId): PackageTemplate;

    public function create(string $name, int $seatLimit): PackageTemplate;

    public function update(PackageTemplate $template): void;

    public function retire(int $packageId, int $now): void;

    /** @param array<string, bool|int|string> $redactedMetadata */
    public function appendAudit(int $actorId, int $packageId, string $action, array $redactedMetadata, int $now): void;
}
