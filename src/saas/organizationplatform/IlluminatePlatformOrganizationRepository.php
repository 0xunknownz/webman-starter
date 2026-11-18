<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organizationplatform;

use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Z0x\WebmanStarter\saas\organization\IlluminateOrganizationRepository;
use Z0x\WebmanStarter\saas\organization\Organization;
use Z0x\WebmanStarter\saas\organization\OrganizationRepositoryInterface;
use Z0x\WebmanStarter\saas\organization\SubscriptionWindow;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class IlluminatePlatformOrganizationRepository implements PlatformOrganizationRepositoryInterface
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

    public function create(string $name, int $packageId, SubscriptionWindow $subscription): Organization
    {
        return $this->organizations->create($name, $packageId, $subscription);
    }

    public function rename(Organization $organization, string $name): string
    {
        $name = IlluminateOrganizationRepository::validatedName($name, 100, 'organization_name_invalid');
        if ($name !== $organization->name) {
            $this->update($organization->id, ['name' => $name]);
        }

        return $name;
    }

    public function setSuspended(int $organizationId, ?int $suspendedAt): void
    {
        $this->update($organizationId, ['suspended_at' => $suspendedAt === null ? null : gmdate('Y-m-d H:i:s', $suspendedAt)]);
    }

    public function setArchived(int $organizationId, ?int $archivedAt): void
    {
        $this->update($organizationId, ['archived_at' => $archivedAt === null ? null : gmdate('Y-m-d H:i:s', $archivedAt)]);
    }

    /** @param array<string, string|null> $values */
    private function update(int $organizationId, array $values): void
    {
        if ($this->connection->transactionLevel() < 1) {
            throw new LogicException('Platform organization mutation requires an organization transaction.');
        }
        $this->connection->table('starter_organizations')->where('id', $organizationId)->update($values);
    }
}
