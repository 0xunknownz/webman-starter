<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\http;

use Closure;
use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\service\SmsLoginService;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class AuthController
{
    /** @param Closure():int $clock */
    public function __construct(
        private readonly SmsLoginService $sms,
        private readonly SessionService $sessions,
        private readonly Closure $clock,
        private readonly ClientAddress $clientAddress,
    ) {
    }

    public function sendSms(Request $request): Response
    {
        $phone = MainlandMobileNumber::fromInput(self::text($request, 'phone', 250));
        $ip = $this->clientAddress->resolve($request);
        $id = $this->sms->send($phone, $ip, ($this->clock)());

        return ApiResponse::success(['accepted' => true, 'challenge_id' => $id], '短信请求已受理', request: $request)
            ->header('Cache-Control', 'no-store');
    }

    public function loginSms(Request $request): Response
    {
        $phone = MainlandMobileNumber::fromInput(self::text($request, 'phone', 250));
        $purpose = $request->post('purpose', SessionService::PURPOSE_ORGANIZATION);
        if (!in_array($purpose, [SessionService::PURPOSE_PLATFORM, SessionService::PURPOSE_ORGANIZATION], true)) {
            throw BusinessException::validation(['purpose' => ['用途无效']]);
        }
        $id = self::text($request, 'challenge_id', 100);
        $code = self::text($request, 'code', 32);
        $tokens = $this->sms->login($phone, $id, $code, $purpose, ($this->clock)());

        return ApiResponse::success($tokens, request: $request)->header('Cache-Control', 'no-store');
    }

    public function refresh(Request $request): Response
    {
        $token = self::text($request, 'refresh_token', 256);
        $tokens = $this->sessions->refresh($token, ($this->clock)());

        return ApiResponse::success($tokens, request: $request)->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): Response
    {
        $this->sessions->logout($this->identity($request));

        return ApiResponse::deleted(request: $request)->header('Cache-Control', 'no-store');
    }

    public static function platformMe(Request $request, SessionIdentity $identity): Response
    {
        return ApiResponse::success(['account_id' => $identity->accountId], request: $request)
            ->header('Cache-Control', 'no-store');
    }

    public function identity(Request $request, ?string $purpose = null): SessionIdentity
    {
        $header = $request->header('authorization');
        if (!is_string($header) || $header === '') {
            throw BusinessException::unauthenticated();
        }
        if (preg_match('/\ABearer ([^\s]+)\z/i', $header, $match) !== 1 || strlen($match[1]) > 4096) {
            throw BusinessException::tokenInvalid();
        }

        return $this->sessions->authenticate($match[1], $purpose, ($this->clock)());
    }

    private static function text(Request $request, string $field, int $maxLength): string
    {
        $value = $request->post($field);
        if (!is_string($value) || strlen($value) > $maxLength) {
            throw BusinessException::validation([$field => ['参数格式无效']]);
        }

        return $value;
    }
}
