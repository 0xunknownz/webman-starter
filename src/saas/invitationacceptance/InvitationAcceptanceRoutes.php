<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitationacceptance;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\Route;

final class InvitationAcceptanceRoutes
{
    /** @param callable():InvitationAcceptanceController $controller */
    public static function register(callable $controller): void
    {
        Route::post('/invitations/resolve', static fn (Request $request): Response => $controller()->resolve($request));
        Route::post('/invitations/sms/send', static fn (Request $request): Response => $controller()->send($request));
        Route::post('/invitations/sms/verify', static fn (Request $request): Response => $controller()->verify($request));
        Route::post('/invitations/accept', static fn (Request $request): Response => $controller()->accept($request));
    }
}
