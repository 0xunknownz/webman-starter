<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\recovery;

use Closure;
use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\email\EmailAddress;
use Z0x\WebmanStarter\saas\http\ClientAddress;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class EmailRecoveryController
{
    /** @param Closure():int $clock */
    public function __construct(
        private readonly EmailRecoveryService $service,
        private readonly Closure $clock,
        private readonly ClientAddress $clientAddress,
    ) {
    }

    public function send(Request $request): Response
    {
        $email = EmailAddress::fromInput($request->post('email'));
        $id = $this->service->send($email, $this->clientAddress->resolve($request), ($this->clock)());

        return self::success($request, ['accepted' => true, 'challenge_id' => $id], '邮件请求已受理');
    }

    public function verify(Request $request): Response
    {
        $email = EmailAddress::fromInput($request->post('email'));
        $challenge = self::text($request, 'challenge_id', 32);
        $code = self::text($request, 'code', 6);
        if (preg_match('/\A[0-9a-f]{32}\z/', $challenge) !== 1) {
            throw BusinessException::validation(['challenge_id' => ['参数格式无效']]);
        }
        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            throw BusinessException::validation(['code' => ['参数格式无效']]);
        }
        $newPhone = MainlandMobileNumber::fromInput(self::text($request, 'new_phone', 250));

        $proof = $this->service->verify($email, $challenge, $code, $newPhone, $this->clock);
        $proof['verified_until'] = gmdate(DATE_ATOM, $proof['verified_until']);

        return self::success($request, $proof);
    }

    public function sendNew(Request $request, string $flow): Response
    {
        $id = $this->service->sendNew($flow, self::token($request), $this->clientAddress->resolve($request), $this->clock);

        return self::success($request, ['accepted' => true, 'challenge_id' => $id], '短信请求已受理');
    }

    public function verifyNew(Request $request, string $flow): Response
    {
        $until = $this->service->verifyNew($flow, self::token($request), self::text($request, 'challenge_id', 100),
            self::text($request, 'code', 32), $this->clock);

        return self::success($request, ['verified_until' => gmdate(DATE_ATOM, $until)]);
    }

    public function complete(Request $request, string $flow): Response
    {
        $this->service->complete($flow, self::token($request), $this->clock);

        return self::success($request, ['changed' => true]);
    }

    private static function token(Request $request): string
    {
        $mediaType = strtolower(trim(explode(';', $request->header('content-type', ''), 2)[0]));
        $token = $mediaType === 'application/json' ? $request->post('recovery_token') : null;

        return is_string($token) ? $token : '';
    }

    private static function text(Request $request, string $field, int $maxLength): string
    {
        $value = $request->post($field);
        if (!is_string($value) || strlen($value) > $maxLength) {
            throw BusinessException::validation([$field => ['参数格式无效']]);
        }

        return $value;
    }

    /** @param array<string,bool|int|string> $data */
    private static function success(Request $request, array $data, string $message = 'success'): Response
    {
        return ApiResponse::success($data, $message, request: $request)->header('Cache-Control', 'no-store');
    }
}
