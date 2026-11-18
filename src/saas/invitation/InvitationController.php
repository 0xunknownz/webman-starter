<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitation;

use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class InvitationController
{
    public function __construct(private readonly ManageInvitationsService $invitations)
    {
    }

    public function create(Request $request, SessionIdentity $identity, string $organizationId): Response
    {
        $fields = $request->post();
        $data = $this->invitations->create($identity, self::id($organizationId), $fields['phone'] ?? null,
            array_key_exists('role', $fields) ? $fields['role'] : 'member');

        return ApiResponse::created($data, request: $request)->header('Cache-Control', 'no-store');
    }

    public function keep(Request $request, SessionIdentity $identity, string $organizationId, string $invitationId): Response
    {
        $data = $this->invitations->keep($identity, self::id($organizationId), self::id($invitationId));

        return ApiResponse::success($data, request: $request)->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, SessionIdentity $identity, string $organizationId, string $invitationId): Response
    {
        $this->invitations->revoke($identity, self::id($organizationId), self::id($invitationId));

        return ApiResponse::deleted(request: $request)->header('Cache-Control', 'no-store');
    }

    public function reissue(Request $request, SessionIdentity $identity, string $organizationId, string $invitationId): Response
    {
        $data = $this->invitations->reissue($identity, self::id($organizationId), self::id($invitationId));

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
