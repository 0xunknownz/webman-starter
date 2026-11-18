<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\email;

use Closure;
use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\http\AuthController;
use Z0x\WebmanStarter\saas\http\ClientAddress;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class EmailController
{
    /** @param Closure():int $clock */
    public function __construct(
        private readonly EmailSignInService $email,
        private readonly AuthController $auth,
        private readonly Closure $clock,
        private readonly ClientAddress $clientAddress,
    ) {
    }

    public function send(Request $request): Response
    {
        $identity = $this->auth->identity($request);
        $email = EmailAddress::fromInput($request->post('email'));
        $id = $this->email->send($identity->accountId, $email, $this->clientAddress->resolve($request), ($this->clock)());

        return ApiResponse::success(['accepted' => true, 'challenge_id' => $id], '邮件请求已受理', request: $request)
            ->header('Cache-Control', 'no-store');
    }

    public function bind(Request $request): Response
    {
        $identity = $this->auth->identity($request);
        $email = EmailAddress::fromInput($request->post('email'));
        $id = self::text($request, 'challenge_id', 100);
        $code = self::text($request, 'code', 32);
        $this->email->bind($identity, $email, $id, $code, ($this->clock)());

        return ApiResponse::success(request: $request)->header('Cache-Control', 'no-store');
    }

    public function setPassword(Request $request): Response
    {
        $identity = $this->auth->identity($request);
        $this->email->setPassword($identity, $request->post('password'), ($this->clock)());

        return ApiResponse::success(request: $request)->header('Cache-Control', 'no-store');
    }

    public function login(Request $request): Response
    {
        $email = EmailAddress::fromInput($request->post('email'));
        $purpose = $request->post('purpose', SessionService::PURPOSE_ORGANIZATION);
        if (!in_array($purpose, [SessionService::PURPOSE_PLATFORM, SessionService::PURPOSE_ORGANIZATION], true)) {
            throw BusinessException::validation(['purpose' => ['用途无效']]);
        }
        $session = $this->email->login($email, $request->post('password'), $purpose, ($this->clock)());

        return ApiResponse::success($session, request: $request)->header('Cache-Control', 'no-store');
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
