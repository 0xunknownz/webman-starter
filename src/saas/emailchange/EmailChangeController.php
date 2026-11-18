<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\emailchange;

use Closure;
use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\email\EmailAddress;
use Z0x\WebmanStarter\saas\http\ClientAddress;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class EmailChangeController
{
    /** @param Closure():int $clock */
    public function __construct(
        private readonly EmailChangeService $changes,
        private readonly Closure $clock,
        private readonly ClientAddress $clientAddress,
    ) {
    }

    public function start(Request $request, SessionIdentity $identity): Response
    {
        $started = $this->changes->start($identity, EmailAddress::fromInput($request->post('new_email')),
            self::text($request, 'channel', 5), $this->clientAddress->resolve($request), $this->clock);

        return self::sent($request, $started);
    }

    public function sendOld(Request $request, SessionIdentity $identity, string $flow): Response
    {
        return $this->send($request, $identity, $flow, false);
    }

    public function verifyOld(Request $request, SessionIdentity $identity, string $flow): Response
    {
        return $this->verify($request, $identity, $flow, false);
    }

    public function sendNew(Request $request, SessionIdentity $identity, string $flow): Response
    {
        return $this->send($request, $identity, $flow, true);
    }

    public function verifyNew(Request $request, SessionIdentity $identity, string $flow): Response
    {
        return $this->verify($request, $identity, $flow, true);
    }

    public function complete(Request $request, SessionIdentity $identity, string $flow): Response
    {
        $this->changes->complete($flow, $identity, $this->clock);

        return self::success($request, ['changed' => true]);
    }

    private function send(Request $request, SessionIdentity $identity, string $flow, bool $new): Response
    {
        $challenge = $this->changes->send($flow, $identity, $new, $this->clientAddress->resolve($request), $this->clock);

        return self::sent($request, ['challenge_id' => $challenge]);
    }

    private function verify(Request $request, SessionIdentity $identity, string $flow, bool $new): Response
    {
        $until = $this->changes->verify($flow, $identity, $new, self::text($request, 'challenge_id', 100),
            self::text($request, 'code', 32), $this->clock);

        return self::success($request, ['verified_until' => gmdate(DATE_ATOM, $until)]);
    }

    /** @param array<string,string> $data */
    private static function sent(Request $request, array $data): Response
    {
        return ApiResponse::success(['accepted' => true] + $data, '验证码请求已受理', request: $request)
            ->header('Cache-Control', 'no-store');
    }

    /** @param array<string,bool|string> $data */
    private static function success(Request $request, array $data): Response
    {
        return ApiResponse::success($data, request: $request)->header('Cache-Control', 'no-store');
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
