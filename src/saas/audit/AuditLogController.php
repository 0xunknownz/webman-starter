<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\audit;

use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class AuditLogController
{
    public function __construct(private readonly AuditLogService $logs)
    {
    }

    public function platform(Request $request): Response
    {
        return $this->page($request, null);
    }

    public function organization(Request $request, string $organizationId): Response
    {
        $id = filter_var($organizationId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw BusinessException::notFound();
        }

        return $this->page($request, $id);
    }

    private function page(Request $request, ?int $organizationId): Response
    {
        $identity = $request->starterIdentity ?? null;
        if (!$identity instanceof SessionIdentity) {
            throw BusinessException::unauthenticated();
        }
        $data = $this->logs->page($identity, $organizationId, $request->get('page', 1), $request->get('per_page', 20), $request->get('action'));

        return ApiResponse::success($data, request: $request)->header('Cache-Control', 'no-store');
    }
}
