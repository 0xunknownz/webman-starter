<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\subscription;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use Webman\Route;

final class SubscriptionRoutes
{
    /** @param callable(): SubscriptionController $controller */
    public static function register(callable $controller, MiddlewareInterface $platformAuth): void
    {
        $subscription = '/platform/organizations/{organizationId:[1-9][0-9]*}/subscription';

        Route::patch($subscription . '/window', static fn (Request $request, string $organizationId): Response =>
            $controller()->updateWindow($request, $request->starterIdentity, $organizationId))->middleware($platformAuth);
        Route::patch($subscription . '/package', static fn (Request $request, string $organizationId): Response =>
            $controller()->switchPackage($request, $request->starterIdentity, $organizationId))->middleware($platformAuth);
    }
}
