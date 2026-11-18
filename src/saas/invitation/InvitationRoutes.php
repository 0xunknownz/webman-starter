<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitation;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use Webman\Route;

final class InvitationRoutes
{
    /**
     * Register inside the host's starter route group. The supplied middleware authenticates organization sessions
     * and attaches the identity to the request, following the starter SessionMiddleware contract.
     *
     * @param callable(): InvitationController $controller
     */
    public static function register(callable $controller, MiddlewareInterface $organizationAuth): void
    {
        $collection = '/organizations/{organizationId:[1-9][0-9]*}/invitations';
        $resource = $collection . '/{invitationId:[1-9][0-9]*}';
        Route::post($collection, static fn (Request $request, string $organizationId): Response =>
            $controller()->create($request, $request->starterIdentity, $organizationId))->middleware($organizationAuth);
        Route::get($resource . '/link', static fn (Request $request, string $organizationId, string $invitationId): Response =>
            $controller()->keep($request, $request->starterIdentity, $organizationId, $invitationId))->middleware($organizationAuth);
        Route::delete($resource, static fn (Request $request, string $organizationId, string $invitationId): Response =>
            $controller()->revoke($request, $request->starterIdentity, $organizationId, $invitationId))->middleware($organizationAuth);
        Route::post($resource . '/reissue', static fn (Request $request, string $organizationId, string $invitationId): Response =>
            $controller()->reissue($request, $request->starterIdentity, $organizationId, $invitationId))->middleware($organizationAuth);
    }
}
