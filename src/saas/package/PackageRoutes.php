<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\package;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use Webman\Route;

final class PackageRoutes
{
    /** @param callable():PackageController $controller */
    public static function register(callable $controller, MiddlewareInterface $platformAuth): void
    {
        $collection = '/platform/packages';
        $resource = $collection . '/{packageId:[1-9][0-9]*}';
        Route::post($collection, static fn (Request $request): Response =>
            $controller()->create($request, $request->starterIdentity))->middleware($platformAuth);
        Route::patch($resource, static fn (Request $request, string $packageId): Response =>
            $controller()->update($request, $request->starterIdentity, $packageId))->middleware($platformAuth);
        Route::post($resource . '/retire', static fn (Request $request, string $packageId): Response =>
            $controller()->retire($request, $request->starterIdentity, $packageId))->middleware($platformAuth);
    }
}
