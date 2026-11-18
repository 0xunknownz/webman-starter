<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\ownership;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\Route;
use Z0x\WebmanStarter\saas\http\AuthFactory;
use Z0x\WebmanStarter\saas\http\SessionMiddleware;
use Z0x\WebmanStarter\saas\session\SessionService;

final class OwnershipTransferRoutes
{
    /** Register within the starter's route group. */
    public static function register(AuthFactory $auth): void
    {
        $session = new SessionMiddleware($auth, SessionService::PURPOSE_ORGANIZATION);
        $collection = '/organizations/{organizationId:[1-9][0-9]*}/ownership-transfers';
        $resource = $collection . '/{transferId:[1-9][0-9]*}';
        Route::post($collection, static fn (Request $request, string $organizationId): Response =>
            $auth->ownershipTransferController()->start($request, $request->starterIdentity, $organizationId))->middleware($session);
        Route::get($collection . '/pending', static fn (Request $request, string $organizationId): Response =>
            $auth->ownershipTransferController()->pending($request, $request->starterIdentity, $organizationId))->middleware($session);
        Route::delete($resource, static fn (Request $request, string $organizationId, string $transferId): Response =>
            $auth->ownershipTransferController()->revoke($request, $request->starterIdentity, $organizationId, $transferId))->middleware($session);
        Route::post($resource . '/accept', static fn (Request $request, string $organizationId, string $transferId): Response =>
            $auth->ownershipTransferController()->accept($request, $request->starterIdentity, $organizationId, $transferId))->middleware($session);
    }
}
