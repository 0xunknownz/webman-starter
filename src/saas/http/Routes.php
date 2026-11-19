<?php
declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\http;

use InvalidArgumentException;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\Route;
use Z0x\WebmanStarter\saas\invitation\InvitationRoutes;
use Z0x\WebmanStarter\saas\invitationacceptance\InvitationAcceptanceRoutes;
use Z0x\WebmanStarter\saas\membership\MembershipRoutes;
use Z0x\WebmanStarter\saas\organizationplatform\PlatformOrganizationRoutes;
use Z0x\WebmanStarter\saas\ownership\OwnershipTransferRoutes;
use Z0x\WebmanStarter\saas\package\PackageRoutes;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\saas\subscription\SubscriptionRoutes;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class Routes
{
    public const DEFAULT_PREFIX = '/saas';

    /** Register the starter's routes from the host's config/route.php. */
    public static function register(string $prefix = self::DEFAULT_PREFIX, ?AuthFactory $auth = null): void
    {
        if (preg_match('~\A/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\z~', $prefix) !== 1) {
            throw new InvalidArgumentException('Starter route prefix must use literal path segments, e.g. /api/saas.');
        }

        Route::group($prefix, static function () use ($auth): void {
            Route::get('/health', static function (Request $request): Response {
                return ApiResponse::success(['status' => 'ok'], request: $request)
                    ->header('Cache-Control', 'no-store');
            });
            if ($auth !== null) {
                MembershipRoutes::register($auth);
                OwnershipTransferRoutes::register($auth);
                InvitationAcceptanceRoutes::register($auth->invitationAcceptanceController(...));
                $organization = new SessionMiddleware($auth, SessionService::PURPOSE_ORGANIZATION);
                InvitationRoutes::register($auth->invitationController(...), $organization);
                PlatformOrganizationRoutes::registerOwnerArchive($auth->platformOrganizationController(...), $organization);
                Route::post('/auth/sms/send', static fn (Request $request): Response => $auth->controller()->sendSms($request));
                Route::post('/auth/sms/login', static fn (Request $request): Response => $auth->controller()->loginSms($request));
                Route::post('/auth/refresh', static fn (Request $request): Response => $auth->controller()->refresh($request));
                Route::post('/auth/logout', static fn (Request $request): Response => $auth->controller()->logout($request));
                Route::get('/account/profile', static function (Request $request) use ($auth): Response {
                    return $auth->profileController()->get($request, $auth->controller()->identity($request));
                });
                Route::patch('/account/profile', static function (Request $request) use ($auth): Response {
                    return $auth->profileController()->update($request, $auth->controller()->identity($request));
                });
                Route::post('/account/email/send', static fn (Request $request): Response => $auth->emailController()->send($request));
                Route::post('/account/email/bind', static fn (Request $request): Response => $auth->emailController()->bind($request));
                Route::put('/account/password', static fn (Request $request): Response => $auth->emailController()->setPassword($request));
                Route::post('/auth/email/login', static fn (Request $request): Response => $auth->emailController()->login($request));
                Route::post('/auth/password/reset/send', static fn (Request $request): Response => $auth->passwordResetController()->send($request));
                Route::post('/auth/password/reset/complete', static fn (Request $request): Response => $auth->passwordResetController()->complete($request));
                Route::post('/auth/phone/recovery/email/send', static fn (Request $request): Response =>
                    $auth->emailRecoveryController()->send($request));
                Route::post('/auth/phone/recovery/email/verify', static fn (Request $request): Response =>
                    $auth->emailRecoveryController()->verify($request));
                $recovery = '/auth/phone/recovery/email/{flow}';
                Route::post($recovery . '/new/send', static fn (Request $request, string $flow): Response =>
                    $auth->emailRecoveryController()->sendNew($request, $flow));
                Route::post($recovery . '/new/verify', static fn (Request $request, string $flow): Response =>
                    $auth->emailRecoveryController()->verifyNew($request, $flow));
                Route::post($recovery . '/complete', static fn (Request $request, string $flow): Response =>
                    $auth->emailRecoveryController()->complete($request, $flow));

                $platform = new SessionMiddleware($auth, SessionService::PURPOSE_PLATFORM);
                PlatformOrganizationRoutes::register($auth->platformOrganizationController(...), $platform);
                PackageRoutes::register($auth->packageController(...), $platform);
                SubscriptionRoutes::register($auth->subscriptionController(...), $platform);
                Route::get('/platform/me', static fn (Request $request): Response => AuthController::platformMe($request, $request->starterIdentity))
                    ->middleware($platform);
                Route::post('/platform/admins', static fn (Request $request): Response => $auth->platformAdminController()->grant($request))
                    ->middleware($platform);
                Route::delete('/platform/admins/{accountId:[1-9][0-9]*}', static fn (Request $request, string $accountId): Response =>
                    $auth->platformAdminController()->revoke($request, $accountId))->middleware($platform);

                Route::post('/platform/phone-recovery', static fn (Request $request): Response =>
                    $auth->operatorRecoveryController()->start($request))->middleware($platform);
                $operator = '/platform/phone-recovery/{flow}';
                Route::post($operator . '/new/send', static fn (Request $request, string $flow): Response =>
                    $auth->operatorRecoveryController()->sendNew($request, $flow))->middleware($platform);
                Route::post($operator . '/new/verify', static fn (Request $request, string $flow): Response =>
                    $auth->operatorRecoveryController()->verifyNew($request, $flow))->middleware($platform);
                Route::post($operator . '/complete', static fn (Request $request, string $flow): Response =>
                    $auth->operatorRecoveryController()->complete($request, $flow))->middleware($platform);

                $account = new SessionMiddleware($auth);
                Route::post('/account/email/change', static fn (Request $request): Response =>
                    $auth->emailChangeController()->start($request, $request->starterIdentity))->middleware($account);
                $email = '/account/email/change/{flow}';
                Route::post($email . '/old/send', static fn (Request $request, string $flow): Response =>
                    $auth->emailChangeController()->sendOld($request, $request->starterIdentity, $flow))->middleware($account);
                Route::post($email . '/old/verify', static fn (Request $request, string $flow): Response =>
                    $auth->emailChangeController()->verifyOld($request, $request->starterIdentity, $flow))->middleware($account);
                Route::post($email . '/new/send', static fn (Request $request, string $flow): Response =>
                    $auth->emailChangeController()->sendNew($request, $request->starterIdentity, $flow))->middleware($account);
                Route::post($email . '/new/verify', static fn (Request $request, string $flow): Response =>
                    $auth->emailChangeController()->verifyNew($request, $request->starterIdentity, $flow))->middleware($account);
                Route::post($email . '/complete', static fn (Request $request, string $flow): Response =>
                    $auth->emailChangeController()->complete($request, $request->starterIdentity, $flow))->middleware($account);
                Route::post('/account/phone/change', static fn (Request $request): Response =>
                    $auth->phoneChangeController()->start($request, $request->starterIdentity))->middleware($account);
                $flow = '/account/phone/change/{flow}';
                Route::post($flow . '/old/send', static fn (Request $request, string $flow): Response =>
                    $auth->phoneChangeController()->sendOld($request, $request->starterIdentity, $flow))->middleware($account);
                Route::post($flow . '/old/verify', static fn (Request $request, string $flow): Response =>
                    $auth->phoneChangeController()->verifyOld($request, $request->starterIdentity, $flow))->middleware($account);
                Route::post($flow . '/new/send', static fn (Request $request, string $flow): Response =>
                    $auth->phoneChangeController()->sendNew($request, $request->starterIdentity, $flow))->middleware($account);
                Route::post($flow . '/new/verify', static fn (Request $request, string $flow): Response =>
                    $auth->phoneChangeController()->verifyNew($request, $request->starterIdentity, $flow))->middleware($account);
                Route::post($flow . '/complete', static fn (Request $request, string $flow): Response =>
                    $auth->phoneChangeController()->complete($request, $request->starterIdentity, $flow))->middleware($account);
            }
        })->middleware(new SafeErrorMiddleware());
    }
}
