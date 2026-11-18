<?php

declare(strict_types=1);

namespace app\example;

use Closure;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccess;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccessService;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationOperation;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

/** @phpstan-import-type RecordData from RecordRepositoryInterface */
final class ManageRecordsService
{
    public const READ = 'example.records.read';
    public const WRITE = 'example.records.write';

    /** @param Closure(): int $clock */
    public function __construct(
        private readonly OrganizationAccessService $access,
        private readonly SessionService $sessions,
        private readonly RecordRepositoryInterface $records,
        private readonly Closure $clock,
    ) {
    }

    /** @return list<RecordData> */
    public function list(SessionIdentity $identity, int $organizationId): array
    {
        return $this->records->listForOrganization($this->queryOrganization($identity, $organizationId));
    }

    /** @return RecordData */
    public function show(SessionIdentity $identity, int $organizationId, int $recordId): array
    {
        return $this->records->find($this->queryOrganization($identity, $organizationId), $recordId)
            ?? throw BusinessException::notFound();
    }

    /** @return RecordData */
    public function create(SessionIdentity $identity, int $organizationId, mixed $title): array
    {
        return $this->write($identity, $organizationId, function (OrganizationAccess $context) use ($title): array {
            $id = $context->organization->id;
            $name = $this->validateTitle($title);
            $at = gmdate('Y-m-d H:i:s', ($this->clock)());

            return [
                'id' => $this->records->insert($id, $name, $at),
                'organization_id' => $id,
                'title' => $name,
                'created_at' => $at,
                'updated_at' => $at,
            ];
        });
    }

    /** @return RecordData */
    public function update(SessionIdentity $identity, int $organizationId, int $recordId, mixed $title): array
    {
        return $this->write($identity, $organizationId, function (OrganizationAccess $context) use ($recordId, $title): array {
            $id = $context->organization->id;
            $record = $this->records->find($id, $recordId, lock: true) ?? throw BusinessException::notFound();
            $record['title'] = $this->validateTitle($title);
            $record['updated_at'] = gmdate('Y-m-d H:i:s', ($this->clock)());
            $this->records->update($id, $recordId, $record['title'], $record['updated_at']);

            return $record;
        });
    }

    private function queryOrganization(SessionIdentity $identity, int $organizationId): int
    {
        return $this->access->require(
            $identity,
            $organizationId,
            OrganizationOperation::BusinessQuery,
            ($this->clock)(),
            self::READ,
        )->organization->id;
    }

    /**
     * @param Closure(OrganizationAccess): RecordData $operation
     * @return RecordData
     */
    private function write(SessionIdentity $identity, int $organizationId, Closure $operation): array
    {
        return $this->access->write(
            $this->sessions,
            $identity,
            $organizationId,
            OrganizationOperation::BusinessWrite,
            ($this->clock)(),
            $operation,
            self::WRITE,
            clock: $this->clock,
        );
    }

    private function validateTitle(mixed $value): string
    {
        $title = is_string($value) ? trim($value) : '';
        if (preg_match('/\A.{1,200}\z/us', $title) !== 1) {
            throw BusinessException::validation(['title' => ['标题须为 1 至 200 个字符']]);
        }

        return $title;
    }
}
