<?php

declare(strict_types=1);

namespace app\example;

/** @phpstan-type RecordData array{id: int, organization_id: int, title: string, created_at: string, updated_at: string} */
interface RecordRepositoryInterface
{
    /** @return list<RecordData> */
    public function listForOrganization(int $organizationId): array;

    /** @return RecordData|null */
    public function find(int $organizationId, int $recordId, bool $lock = false): ?array;

    public function insert(int $organizationId, string $title, string $at): int;

    public function update(int $organizationId, int $recordId, string $title, string $at): void;
}
