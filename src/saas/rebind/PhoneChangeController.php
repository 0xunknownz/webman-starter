<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\rebind;

use Closure;
use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\http\ClientAddress;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class PhoneChangeController
{
    /** @param Closure():int $clock */
    public function __construct(
        private readonly PhoneChangeService $changes,
        private readonly Closure $clock,
        private readonly ClientAddress $clientAddress,
    ) {
    }

    public function start(Request $request, SessionIdentity $identity): Response
    {
        $phone = MainlandMobileNumber::fromInput(self::text($request, 'new_phone', 250));
        $started = $this->changes->start($identity, $phone, $this->clientAddress->resolve($request), ($this->clock)());

        return self::sent($request, $started);
    }

    public function sendOld(Request $request, SessionIdentity $identity, string $flow): Response
    {
        $challenge = $this->changes->sendOld($flow, $identity, $this->clientAddress->resolve($request), $this->clock);

        return self::sent($request, ['challenge_id' => $challenge]);
    }

    public function verifyOld(Request $request, SessionIdentity $identity, string $flow): Response
    {
        $until = $this->changes->verifyOld($flow, $identity, self::text($request, 'challenge_id', 100),
            self::text($request, 'code', 32), $this->clock);

        return self::success($request, ['verified_until' => gmdate(DATE_ATOM, $until)]);
    }

    public function sendNew(Request $request, SessionIdentity $identity, string $flow): Response
    {
        $challenge = $this->changes->sendNew($flow, $identity, $this->clientAddress->resolve($request), $this->clock);

        return self::sent($request, ['challenge_id' => $challenge]);
    }

    public function verifyNew(Request $request, SessionIdentity $identity, string $flow): Response
    {
        $until = $this->changes->verifyNew($flow, $identity, self::text($request, 'challenge_id', 100),
            self::text($request, 'code', 32), $this->clock);

        return self::success($request, ['verified_until' => gmdate(DATE_ATOM, $until)]);
    }

    public function complete(Request $request, SessionIdentity $identity, string $flow): Response
    {
        $this->changes->complete($flow, $identity, $this->clock);

        return self::success($request, ['changed' => true]);
    }

    /** @param array<string,string> $data */
    private static function sent(Request $request, array $data): Response
    {
        return ApiResponse::success(['accepted' => true] + $data, '短信请求已受理', request: $request)
            ->header('Cache-Control', 'no-store');
    }

    /** @param array<string,bool|int> $data */
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
