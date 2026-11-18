<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organizationplatform;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use Webman\Route;

final class PlatformOrganizationRoutes
{
    /** @param callable(): PlatformOrganizationController $controller */
    public static function register(callable $controller, MiddlewareInterface $platformAuth): void
    {
        $collection = '/platform/organizations';
        $organization = $collection . '/{organizationId:[1-9][0-9]*}';
        $invitation = $organization . '/owner-invitations/{invitationId:[1-9][0-9]*}';

        Route::post($collection, static fn (Request $request): Response =>
            $controller()->open($request, $request->starterIdentity))->middleware($platformAuth);
        Route::patch($organization . '/name', static fn (Request $request, string $organizationId): Response =>
            $controller()->rename($request, $request->starterIdentity, $organizationId))->middleware($platformAuth);
        Route::post($organization . '/suspend', static fn (Request $request, string $organizationId): Response =>
            $controller()->setSuspended($request, $request->starterIdentity, $organizationId, true))->middleware($platformAuth);
        Route::post($organization . '/unsuspend', static fn (Request $request, string $organizationId): Response =>
            $controller()->setSuspended($request, $request->starterIdentity, $organizationId, false))->middleware($platformAuth);
        Route::post($organization . '/cancel-opening', static fn (Request $request, string $organizationId): Response =>
            $controller()->cancelOpening($request, $request->starterIdentity, $organizationId))->middleware($platformAuth);
        Route::post($organization . '/restore', static fn (Request $request, string $organizationId): Response =>
            $controller()->restore($request, $request->starterIdentity, $organizationId))->middleware($platformAuth);

        Route::get($invitation . '/link', static fn (Request $request, string $organizationId, string $invitationId): Response =>
            $controller()->keep($request, $request->starterIdentity, $organizationId, $invitationId))->middleware($platformAuth);
        Route::delete($invitation, static fn (Request $request, string $organizationId, string $invitationId): Response =>
            $controller()->revoke($request, $request->starterIdentity, $organizationId, $invitationId))->middleware($platformAuth);
        Route::post($invitation . '/reissue', static fn (Request $request, string $organizationId, string $invitationId): Response =>
            $controller()->reissue($request, $request->starterIdentity, $organizationId, $invitationId))->middleware($platformAuth);
        Route::post($invitation . '/change-phone', static fn (Request $request, string $organizationId, string $invitationId): Response =>
            $controller()->changePhone($request, $request->starterIdentity, $organizationId, $invitationId))->middleware($platformAuth);
    }
}
