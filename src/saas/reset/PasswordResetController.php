<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\reset;

use Closure;
use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\email\EmailAddress;
use Z0x\WebmanStarter\saas\http\ClientAddress;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class PasswordResetController
{
    /** @param Closure():int $clock */
    public function __construct(
        private readonly PasswordResetService $reset,
        private readonly Closure $clock,
        private readonly ClientAddress $clientAddress,
    ) {
    }

    public function send(Request $request): Response
    {
        $email = EmailAddress::fromInput($request->post('email'));
        $id = $this->reset->send($email, $this->clientAddress->resolve($request), ($this->clock)());

        return ApiResponse::success(['accepted' => true, 'challenge_id' => $id], '邮件请求已受理', request: $request)
            ->header('Cache-Control', 'no-store');
    }

    public function complete(Request $request): Response
    {
        $email = EmailAddress::fromInput($request->post('email'));
        $id = $request->post('challenge_id');
        $code = $request->post('code');
        if (!is_string($id) || preg_match('/\A[0-9a-f]{32}\z/', $id) !== 1) {
            throw BusinessException::validation(['challenge_id' => ['参数格式无效']]);
        }
        if (!is_string($code) || preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            throw BusinessException::validation(['code' => ['参数格式无效']]);
        }
        $this->reset->complete($email, $id, $code, $request->post('password'), ($this->clock)());

        return ApiResponse::success(request: $request)->header('Cache-Control', 'no-store');
    }
}
