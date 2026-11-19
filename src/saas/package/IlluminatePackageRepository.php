<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\package;

use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Z0x\WebmanStarter\saas\contract\PlatformAdminRepositoryInterface;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class IlluminatePackageRepository implements PackageRepositoryInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly PlatformAdminRepositoryInterface $audit,
    ) {
    }

    public function transaction(Closure $operation): mixed
    {
        return $this->connection->transaction($operation, 5);
    }

    public function lock(int $packageId): PackageTemplate
    {
        $this->requireTransaction();
        $row = $this->connection->table('starter_packages')->where('id', $packageId)->lockForUpdate()->first();
        if ($row === null) {
            throw BusinessException::notFound();
        }

        return new PackageTemplate($packageId, $row->name, (int) $row->seat_limit, $row->retired_at !== null);
    }

    public function lockAvailable(int $packageId): PackageTemplate
    {
        return $this->lock($packageId)->requireAvailable();
    }

    public function create(string $name, int $seatLimit): PackageTemplate
    {
        $this->requireTransaction();
        $id = $this->connection->table('starter_packages')->insertGetId(['name' => $name, 'seat_limit' => $seatLimit]);

        return new PackageTemplate((int) $id, $name, $seatLimit, false);
    }

    public function update(PackageTemplate $template): void
    {
        $this->requireTransaction();
        $this->connection->table('starter_packages')->where('id', $template->id)->update([
            'name' => $template->name,
            'seat_limit' => $template->seatLimit,
        ]);
    }

    public function retire(int $packageId, int $now): void
    {
        $this->requireTransaction();
        $this->connection->table('starter_packages')->where('id', $packageId)
            ->update(['retired_at' => gmdate('Y-m-d H:i:s', $now)]);
    }

    public function appendAudit(int $actorId, int $packageId, string $action, array $redactedMetadata, int $now): void
    {
        $this->requireTransaction();
        $this->audit->appendAudit(
            $action, $actorId, null, ['package_id' => $packageId, 'result' => 'success'] + $redactedMetadata,
            gmdate('Y-m-d H:i:s', $now),
        );
    }

    private function requireTransaction(): void
    {
        if ($this->connection->transactionLevel() < 1) {
            throw new LogicException('A package transaction is required.');
        }
    }
}
