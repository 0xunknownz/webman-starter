<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitationacceptance;

use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\http\ClientAddress;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class InvitationAcceptanceController
{
    public function __construct(
        private readonly AcceptInvitationService $service,
        private readonly ClientAddress $clientAddress,
    ) {
    }

    public function resolve(Request $request): Response
    {
        return self::response($request, $this->service->resolve(self::token($request)));
    }

    public function send(Request $request): Response
    {
        $id = $this->service->send(self::token($request), self::text($request, 'phone', 250), $this->clientAddress->resolve($request));

        return self::response($request, ['accepted' => true, 'challenge_id' => $id]);
    }

    public function verify(Request $request): Response
    {
        return self::response($request, $this->service->verify(
            self::token($request),
            self::text($request, 'phone', 250),
            self::text($request, 'challenge_id', 100),
            self::text($request, 'code', 32),
        ));
    }

    public function accept(Request $request): Response
    {
        return self::response($request, $this->service->accept(
            self::token($request),
            self::text($request, 'phone', 250),
            self::text($request, 'credential', 64, ''),
        ));
    }

    private static function token(Request $request): string
    {
        $token = $request->post('token');
        if (!is_string($token) || strlen($token) !== 97) {
            throw BusinessException::notFound();
        }

        return $token;
    }

    private static function text(Request $request, string $field, int $maxLength, ?string $default = null): string
    {
        $value = $request->post($field, $default);
        if (!is_string($value) || strlen($value) > $maxLength) {
            throw BusinessException::validation([$field => ['参数格式无效']]);
        }

        return $value;
    }

    private static function response(Request $request, array $data): Response
    {
        return ApiResponse::success($data, request: $request)->header('Cache-Control', 'no-store');
    }
}
