<?php

declare(strict_types=1);

namespace app\example;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/** @phpstan-import-type RecordData from RecordRepositoryInterface */
final class IlluminateRecordRepository implements RecordRepositoryInterface
{
    public const TABLE = 'example_organization_records';

    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function listForOrganization(int $organizationId): array
    {
        return $this->forOrganization($organizationId)->orderBy('id')->get()->map($this->data(...))->all();
    }

    public function find(int $organizationId, int $recordId, bool $lock = false): ?array
    {
        $query = $this->forOrganization($organizationId)->where('id', $recordId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row === null ? null : $this->data($row);
    }

    public function insert(int $organizationId, string $title, string $at): int
    {
        return (int) $this->connection->table(self::TABLE)->insertGetId([
            'organization_id' => $organizationId,
            'title' => $title,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    public function update(int $organizationId, int $recordId, string $title, string $at): void
    {
        $this->forOrganization($organizationId)->where('id', $recordId)->update([
            'title' => $title,
            'updated_at' => $at,
        ]);
    }

    private function forOrganization(int $organizationId): Builder
    {
        return $this->connection->table(self::TABLE)->where('organization_id', $organizationId);
    }

    /** @return RecordData */
    private function data(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'organization_id' => (int) $row->organization_id,
            'title' => (string) $row->title,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }
}
