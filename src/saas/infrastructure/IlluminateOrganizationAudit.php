<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\infrastructure;

use Illuminate\Database\ConnectionInterface;
use JsonException;
use PDOException;
use Z0x\WebmanStarter\saas\contract\OrganizationAuditInterface;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class IlluminateOrganizationAudit implements OrganizationAuditInterface
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function append(
        int $organizationId,
        string $action,
        ?int $actorAccountId,
        ?int $targetAccountId,
        array $redactedMetadata,
        int $now,
    ): void {
        try {
            $written = $this->connection->table('starter_audit_logs')->insert([
                'organization_id' => $organizationId,
                'action' => $action,
                'actor_account_id' => $actorAccountId,
                'target_account_id' => $targetAccountId,
                'metadata' => json_encode($redactedMetadata, JSON_THROW_ON_ERROR),
                'created_at' => gmdate('Y-m-d H:i:s', $now),
            ]);
        } catch (PDOException | JsonException) {
            $written = false;
        }
        if (!$written) {
            throw new BusinessException('组织审计记录未写入', 'audit_unavailable', 503);
        }
    }
}
