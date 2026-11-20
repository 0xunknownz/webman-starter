<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\audit;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

final class IlluminateAuditLogRepository implements AuditLogRepositoryInterface
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function count(?int $organizationId, ?string $action): int
    {
        return $this->query($organizationId, $action)->count();
    }

    public function read(?int $organizationId, ?string $action, int $offset = 0, ?int $limit = null): iterable
    {
        $query = $this->query($organizationId, $action)->orderByDesc('created_at')->orderByDesc('id');
        if ($limit !== null) {
            return $query->offset($offset)->limit($limit)->get();
        }

        return $query->cursor();
    }

    private function query(?int $organizationId, ?string $action): Builder
    {
        $query = $this->connection->table('starter_audit_logs')->useWritePdo()
            ->select('id', 'organization_id', 'action', 'actor_account_id', 'target_account_id', 'created_at', 'metadata');
        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId)->whereIn('action', AuditEvent::organizationActions());
        } else {
            $query->where(static function (Builder $scope): void {
                $scope->where(static fn (Builder $global): Builder => $global->whereNull('organization_id')->whereIn('action', AuditEvent::globalActions()))
                    ->orWhere(static function (Builder $operations): void {
                        $operations->whereNotNull('organization_id')->where(static function (Builder $visible): void {
                            $visible->whereIn('action', AuditEvent::PLATFORM_ORGANIZATION_ACTIONS)
                                ->orWhere(static fn (Builder $ownerInvites): Builder => $ownerInvites
                                    ->whereIn('action', AuditEvent::INVITATION_ACTIONS)
                                    ->where('metadata->role', 'owner'))
                                ->orWhere(static fn (Builder $memberRevocation): Builder => $memberRevocation
                                    ->where('action', 'invitation_revoked')->where('metadata->source', 'platform'));
                        });
                    });
            });
        }
        if ($action !== null) {
            $query->where('action', $action);
        }

        return $query;
    }
}
