<?php

declare(strict_types=1);

namespace app\example;

use Closure;
use support\Db;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\Route;
use Z0x\WebmanStarter\saas\http\AuthFactory;
use Z0x\WebmanStarter\saas\http\SafeErrorMiddleware;
use Z0x\WebmanStarter\saas\http\SessionMiddleware;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;
use Z0x\WebmanStarter\saas\session\SessionService;

final class RecordRoutes
{
    /** @param Closure(): int $clock */
    public static function register(string $prefix, AuthFactory $auth, Closure $clock): void
    {
        // Resolve repositories and session services inside the request, on the same mysql connection.
        $controller = static fn (): RecordController => new RecordController(new ManageRecordsService(
            $auth->organizationAccess([
                ManageRecordsService::READ => [OrganizationRole::Admin, OrganizationRole::Member],
                ManageRecordsService::WRITE => [OrganizationRole::Admin],
            ]),
            $auth->organizationSessions(),
            new IlluminateRecordRepository(Db::connection('mysql')),
            $clock,
        ));

        $session = new SessionMiddleware($auth, SessionService::PURPOSE_ORGANIZATION);
        Route::group($prefix, static function () use ($controller, $session): void {
            $records = '/organizations/{organizationId:[1-9][0-9]*}/records';
            $item = $records . '/{recordId:[1-9][0-9]*}';
            Route::get($records, static fn (Request $request, string $organizationId): Response =>
                $controller()->list($request, $organizationId))->middleware($session);
            Route::get($item, static fn (Request $request, string $organizationId, string $recordId): Response =>
                $controller()->show($request, $organizationId, $recordId))->middleware($session);
            Route::post($records, static fn (Request $request, string $organizationId): Response =>
                $controller()->create($request, $organizationId))->middleware($session);
            Route::patch($item, static fn (Request $request, string $organizationId, string $recordId): Response =>
                $controller()->update($request, $organizationId, $recordId))->middleware($session);
        })->middleware(new SafeErrorMiddleware());
    }
}
