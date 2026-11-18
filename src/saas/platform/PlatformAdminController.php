<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\platform;

use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class PlatformAdminController
{
    public function __construct(private readonly ManagePlatformAdminsService $service)
    {
    }

    public function grant(Request $request): Response
    {
        $identity = $this->identity($request);
        $phone = $request->post('phone');
        if (!is_string($phone) || strlen($phone) > 250) {
            throw BusinessException::validation(['phone' => ['参数格式无效']]);
        }

        return ApiResponse::success(['account_id' => $this->service->grant($identity, $phone)], request: $request)
            ->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, string $accountId): Response
    {
        $identity = $this->identity($request);
        $id = filter_var($accountId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw BusinessException::notFound('Active platform administrator not found.');
        }
        $this->service->revoke($identity, $id);

        return ApiResponse::deleted(request: $request)->header('Cache-Control', 'no-store');
    }

    private function identity(Request $request): SessionIdentity
    {
        $identity = $request->starterIdentity ?? null;
        if (!$identity instanceof SessionIdentity) {
            throw BusinessException::unauthenticated();
        }

        return $identity;
    }
}
