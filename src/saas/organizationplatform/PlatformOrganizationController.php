<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organizationplatform;

use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class PlatformOrganizationController
{
    public function __construct(private readonly ManagePlatformOrganizationsService $organizations)
    {
    }

    public function open(Request $request, SessionIdentity $actor): Response
    {
        $fields = $request->post();
        $data = $this->organizations->open(
            $actor,
            $fields['name'] ?? null,
            $fields['phone'] ?? null,
            $fields['package_id'] ?? null,
            $fields['starts_at'] ?? null,
            $fields['expires_at'] ?? null,
        );

        return ApiResponse::created($data, request: $request)->header('Cache-Control', 'no-store');
    }

    public function rename(Request $request, SessionIdentity $actor, string $organizationId): Response
    {
        $data = $this->organizations->rename($actor, self::id($organizationId), $request->post('name'));

        return ApiResponse::success($data, request: $request);
    }

    public function setSuspended(Request $request, SessionIdentity $actor, string $organizationId, bool $suspended): Response
    {
        $data = $this->organizations->setSuspended($actor, self::id($organizationId), $suspended);

        return ApiResponse::success($data, request: $request);
    }

    public function cancelOpening(Request $request, SessionIdentity $actor, string $organizationId): Response
    {
        return ApiResponse::success($this->organizations->cancelOpening($actor, self::id($organizationId)), request: $request);
    }

    public function archive(Request $request, SessionIdentity $actor, string $organizationId): Response
    {
        return ApiResponse::success($this->organizations->archive($actor, self::id($organizationId)), request: $request);
    }

    public function restore(Request $request, SessionIdentity $actor, string $organizationId): Response
    {
        return ApiResponse::success($this->organizations->restore($actor, self::id($organizationId)), request: $request);
    }

    public function keep(Request $request, SessionIdentity $actor, string $organizationId, string $invitationId): Response
    {
        $data = $this->organizations->keep($actor, self::id($organizationId), self::id($invitationId));

        return ApiResponse::success($data, request: $request)->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, SessionIdentity $actor, string $organizationId, string $invitationId): Response
    {
        $this->organizations->revoke($actor, self::id($organizationId), self::id($invitationId));

        return ApiResponse::deleted(request: $request);
    }

    public function revokeMemberInvitation(Request $request, SessionIdentity $actor, string $organizationId, string $invitationId): Response
    {
        $this->organizations->revokeMemberInvitation($actor, self::id($organizationId), self::id($invitationId));

        return ApiResponse::deleted(request: $request);
    }

    public function reissue(Request $request, SessionIdentity $actor, string $organizationId, string $invitationId): Response
    {
        $data = $this->organizations->reissue($actor, self::id($organizationId), self::id($invitationId));

        return ApiResponse::created($data, request: $request)->header('Cache-Control', 'no-store');
    }

    public function changePhone(Request $request, SessionIdentity $actor, string $organizationId, string $invitationId): Response
    {
        $fields = $request->post();
        $data = $this->organizations->changePhone(
            $actor,
            self::id($organizationId),
            self::id($invitationId),
            $fields['phone'] ?? null,
            $fields['reason'] ?? null,
        );

        return ApiResponse::created($data, request: $request)->header('Cache-Control', 'no-store');
    }

    private static function id(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw BusinessException::notFound();
        }

        return $id;
    }
}
