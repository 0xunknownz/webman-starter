<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\audit;

use Closure;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccessService;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationOperation;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\Pagination;

final class AuditLogService
{
    /** @param Closure():int $clock */
    public function __construct(
        private readonly AuditLogRepositoryInterface $logs,
        private readonly OrganizationAccessService $access,
        private readonly SessionService $sessions,
        private readonly Closure $clock,
    ) {
    }

    public function page(SessionIdentity $identity, ?int $organizationId, mixed $page, mixed $perPage, mixed $action): array
    {
        $now = ($this->clock)();
        if ($organizationId !== null) {
            $context = $this->access->require($identity, $organizationId, OrganizationOperation::OrganizationAudit, $now);
            $organizationId = $context->organization->id;
        } else {
            if ($identity->purpose !== SessionService::PURPOSE_PLATFORM) {
                throw new BusinessException('认证令牌用途不匹配', 'token_purpose_mismatch', 403);
            }
            $this->sessions->requireLiveIdentity($identity, $now);
        }

        $page = self::positiveInteger($page, 'page', intdiv(PHP_INT_MAX, 100));
        $perPage = self::positiveInteger($perPage, 'per_page', 100);
        if ($action !== null && (!is_string($action) || strlen($action) > 64 || !preg_match('/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/', $action))) {
            throw BusinessException::validation(['action' => ['请输入有效的审计事件名称']]);
        }
        $total = $this->logs->count($organizationId, $action);
        $offset = ($page - 1) * $perPage;
        $items = [];
        if ($offset < $total) {
            foreach ($this->logs->read($organizationId, $action, $offset, $perPage) as $row) {
                $items[] = AuditEvent::project($row);
            }
        }

        return ['items' => $items, 'pagination' => Pagination::fromTotal($page, $perPage, $total)->toArray()];
    }

    private static function positiveInteger(mixed $value, string $field, int $max): int
    {
        $number = is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]])
            : false;
        if ($number === false) {
            throw BusinessException::validation([$field => ['页码或每页条数超出允许范围']]);
        }

        return $number;
    }
}
