<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\operator;

use Closure;
use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\http\ClientAddress;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class OperatorRecoveryController
{
    /** @param Closure():int $clock */
    public function __construct(
        private readonly OperatorRecoveryService $recovery,
        private readonly Closure $clock,
        private readonly ClientAddress $clientAddress,
    ) {
    }

    public function start(Request $request): Response
    {
        $accountId = $request->post('account_id');
        if (!is_int($accountId) || $accountId <= 0) {
            throw BusinessException::validation(['account_id' => ['账号标识必须为正整数']]);
        }
        $flow = $this->recovery->startManual(
            $request->starterIdentity,
            $accountId,
            MainlandMobileNumber::fromInput(self::text($request, 'new_phone', 250)),
            OperatorRecoveryEvidence::fromInput($request->post('evidence'), $request->post('reason')),
            $this->clock,
        );

        return self::response($request, ['flow_id' => $flow]);
    }

    public function sendNew(Request $request, string $flow): Response
    {
        $challenge = $this->recovery->sendNew($flow, $request->starterIdentity, $this->clientAddress->resolve($request), $this->clock);

        return self::response($request, ['accepted' => true, 'challenge_id' => $challenge], '短信请求已受理');
    }

    public function verifyNew(Request $request, string $flow): Response
    {
        $until = $this->recovery->verifyNew($flow, $request->starterIdentity, self::text($request, 'challenge_id', 100),
            self::text($request, 'code', 32), $this->clock);

        return self::response($request, ['verified_until' => gmdate(DATE_ATOM, $until)]);
    }

    public function complete(Request $request, string $flow): Response
    {
        $this->recovery->complete($flow, $request->starterIdentity, $this->clock);

        return self::response($request, ['changed' => true]);
    }

    /** @param array<string,bool|int|string> $data */
    private static function response(Request $request, array $data, string $message = 'success'): Response
    {
        return ApiResponse::success($data, $message, request: $request)->header('Cache-Control', 'no-store');
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
