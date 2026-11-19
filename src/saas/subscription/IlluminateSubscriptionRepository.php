<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\subscription;

use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Z0x\WebmanStarter\saas\organization\Organization;
use Z0x\WebmanStarter\saas\organization\OrganizationRepositoryInterface;
use Z0x\WebmanStarter\saas\organization\SubscriptionWindow;
use Z0x\WebmanStarter\saas\package\PackageTemplate;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class IlluminateSubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly OrganizationRepositoryInterface $organizations,
    ) {
    }

    public function transaction(Closure $operation): mixed
    {
        return $this->organizations->transaction($operation);
    }

    public function lock(int $organizationId): Organization
    {
        return $this->organizations->find($organizationId, lock: true) ?? throw BusinessException::notFound();
    }

    public function updateWindow(int $organizationId, SubscriptionWindow $window): void
    {
        $this->update($organizationId, [
            'subscription_starts_at' => gmdate('Y-m-d H:i:s', $window->startsAt->getTimestamp()),
            'subscription_expires_at' => gmdate('Y-m-d H:i:s', $window->expiresAt->getTimestamp()),
        ]);
    }

    public function seatOccupancy(int $organizationId, int $now): int
    {
        return $this->organizations->seatOccupancy($organizationId, $now);
    }

    public function updatePackage(int $organizationId, PackageTemplate $package): void
    {
        $this->update($organizationId, [
            'package_id' => $package->id,
            'package_name' => $package->name,
            'seat_limit' => $package->seatLimit,
        ]);
    }

    /** @param array<string,int|string> $values */
    private function update(int $organizationId, array $values): void
    {
        if ($this->connection->transactionLevel() < 1) {
            throw new LogicException('Subscription mutation requires an organization transaction.');
        }
        $updated = $this->connection->table('starter_organizations')->where('id', $organizationId)->update($values);
        if ($updated !== 1) {
            throw new LogicException('Expected to update one current subscription.');
        }
    }
}
