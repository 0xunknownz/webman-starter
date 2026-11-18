<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\membership;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\Route;
use Z0x\WebmanStarter\saas\http\AuthFactory;
use Z0x\WebmanStarter\saas\http\SessionMiddleware;
use Z0x\WebmanStarter\saas\session\SessionService;

final class MembershipRoutes
{
    /** Register within the starter's route group. */
    public static function register(AuthFactory $auth): void
    {
        $session = new SessionMiddleware($auth, SessionService::PURPOSE_ORGANIZATION);
        $members = '/organizations/{organizationId:[1-9][0-9]*}/members';
        $target = $members . '/{accountId:[1-9][0-9]*}';
        Route::get($members, static fn (Request $request, string $organizationId): Response =>
            $auth->membershipController()->listMembers($request, $organizationId))->middleware($session);
        Route::patch($target . '/role', static fn (Request $request, string $organizationId, string $accountId): Response =>
            $auth->membershipController()->changeRole($request, $organizationId, $accountId))->middleware($session);
        Route::delete($members . '/me', static fn (Request $request, string $organizationId): Response =>
            $auth->membershipController()->exit($request, $organizationId))->middleware($session);
        Route::delete($target, static fn (Request $request, string $organizationId, string $accountId): Response =>
            $auth->membershipController()->remove($request, $organizationId, $accountId))->middleware($session);
    }
}
