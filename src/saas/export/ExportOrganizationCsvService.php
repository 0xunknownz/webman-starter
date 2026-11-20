<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\export;

use Closure;
use Throwable;
use Z0x\WebmanStarter\saas\audit\AuditEvent;
use Z0x\WebmanStarter\saas\audit\AuditLogRepositoryInterface;
use Z0x\WebmanStarter\saas\contract\OrganizationAuditInterface;
use Z0x\WebmanStarter\saas\membership\MembershipRepositoryInterface;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccess;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccessService;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationOperation;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;

final class ExportOrganizationCsvService
{
    /** @param Closure():int $clock */
    public function __construct(
        private readonly OrganizationAccessService $access,
        private readonly SessionService $sessions,
        private readonly MembershipRepositoryInterface $members,
        private readonly AuditLogRepositoryInterface $logs,
        private readonly OrganizationAuditInterface $audit,
        private readonly CsvEncoder $csv,
        private readonly Closure $clock,
    ) {
    }

    public function members(SessionIdentity $identity, int $organizationId): string
    {
        return $this->export($identity, $organizationId, OrganizationOperation::MemberContacts, 'member_contacts',
            function (int $id): array {
                $rows = (function () use ($id): iterable {
                    foreach ($this->members->listMembers($id, true) as $member) {
                        yield [$member['account_id'], $member['display_name'], $member['role'], $member['phone'], $member['email']];
                    }
                })();

                return $this->csv->encode(['account_id', 'display_name', 'role', 'phone', 'email'], $rows);
            });
    }

    public function audit(SessionIdentity $identity, int $organizationId): string
    {
        return $this->export($identity, $organizationId, OrganizationOperation::OrganizationAudit, 'organization_audit',
            function (int $id): array {
                $rows = (function () use ($id): iterable {
                    foreach ($this->logs->read($id, null) as $row) {
                        $event = AuditEvent::project($row);
                        yield [
                            $event['id'], $event['organization_id'], $event['actor_account_id'], $event['target_account_id'],
                            $event['action'], $event['result'], $event['created_at'],
                            json_encode($event['metadata'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        ];
                    }
                })();

                return $this->csv->encode([
                    'id', 'organization_id', 'actor_account_id', 'target_account_id', 'action', 'result', 'created_at', 'metadata',
                ], $rows);
            });
    }

    /** @param Closure(int):array{body:string,rowCount:int} $generate */
    private function export(SessionIdentity $identity, int $organizationId, OrganizationOperation $operation, string $kind, Closure $generate): string
    {
        $outcome = $this->access->write(
            $this->sessions,
            $identity,
            $organizationId,
            $operation,
            ($this->clock)(),
            function (OrganizationAccess $context) use ($identity, $kind, $generate): string|Throwable {
                $id = $context->organization->id;
                try {
                    $csv = $generate($id);
                    $result = 'success';
                } catch (Throwable $error) {
                    $csv = ['body' => $error, 'rowCount' => 0];
                    $result = 'failed';
                }
                $now = $this->sessions->liveTime($identity, $this->clock);
                $this->audit->append($id, $kind . '_exported', $context->accountId, null, [
                    'kind' => $kind, 'result' => $result, 'row_count' => $csv['rowCount'],
                ], $now);

                return $csv['body'];
            },
            clock: $this->clock,
        );
        if ($outcome instanceof Throwable) {
            // The failed attempt is committed before the HTTP boundary produces its generic error.
            throw $outcome;
        }

        return $outcome;
    }
}
