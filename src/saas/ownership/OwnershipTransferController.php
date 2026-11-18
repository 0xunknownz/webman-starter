<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\ownership;

use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class OwnershipTransferController
{
    public function __construct(private readonly OwnershipTransferService $service)
    {
    }

    public function start(Request $request, SessionIdentity $identity, string $organizationId): Response
    {
        $transfer = $this->service->start($identity, self::id($organizationId), $request->post('recipient_account_id'));

        return ApiResponse::created($transfer, request: $request)->header('Cache-Control', 'no-store');
    }

    public function pending(Request $request, SessionIdentity $identity): Response
    {
        return ApiResponse::success(['transfers' => $this->service->pending($identity)], request: $request)
            ->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, SessionIdentity $identity, string $organizationId, string $transferId): Response
    {
        $this->service->revoke($identity, self::id($organizationId), self::id($transferId));

        return ApiResponse::deleted(request: $request)->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request, SessionIdentity $identity, string $organizationId, string $transferId): Response
    {
        $this->service->accept($identity, self::id($organizationId), self::id($transferId));

        return ApiResponse::success(request: $request)->header('Cache-Control', 'no-store');
    }

    private static function id(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw BusinessException::notFound();
        }

        return $id;
    }
}
