<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\membership;

use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class MembershipController
{
    public function __construct(private readonly MembershipService $memberships)
    {
    }

    public function listMembers(Request $request, string $organizationId): Response
    {
        $identity = $this->identity($request);
        $data = $this->memberships->listMembers($identity, $this->id($organizationId));

        return ApiResponse::success($data, request: $request)->header('Cache-Control', 'no-store');
    }

    public function changeRole(Request $request, string $organizationId, string $accountId): Response
    {
        $identity = $this->identity($request);
        $this->memberships->changeRole($identity, $this->id($organizationId), $this->id($accountId), $request->post('role'));

        return ApiResponse::success(request: $request)->header('Cache-Control', 'no-store');
    }

    public function remove(Request $request, string $organizationId, string $accountId): Response
    {
        $identity = $this->identity($request);
        $this->memberships->remove($identity, $this->id($organizationId), $this->id($accountId));

        return ApiResponse::deleted(request: $request)->header('Cache-Control', 'no-store');
    }

    public function exit(Request $request, string $organizationId): Response
    {
        $identity = $this->identity($request);
        $this->memberships->exit($identity, $this->id($organizationId));

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

    private function id(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw BusinessException::notFound();
        }

        return $id;
    }
}
