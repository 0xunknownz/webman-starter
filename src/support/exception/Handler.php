<?php
declare(strict_types=1);

namespace Z0x\WebmanStarter\Support\Exception;

use support\exception\PageNotFoundException;
use Throwable;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\Support\Http\ApiResponse;
use Z0x\WebmanStarter\Support\Http\ErrorCode;

final class Handler extends ExceptionHandler
{
    /**
     * @var array<class-string<Throwable>>
     */
    public $dontReport = [
        BusinessException::class,
    ];

    public function render(Request $request, Throwable $exception): Response
    {
        if ($exception instanceof BusinessException) {
            return ApiResponse::fail(
                code: $exception->getCode(),
                message: $exception->getMessage(),
                status: $exception->status(),
                data: $exception->data(),
                errors: $exception->errors(),
                request: $request
            );
        }

        if ($exception instanceof PageNotFoundException) {
            return ApiResponse::fail(
                code: ErrorCode::ROUTE_NOT_FOUND,
                message: '路由不存在',
                status: 404,
                request: $request
            );
        }

        return ApiResponse::fail(
            code: ErrorCode::INTERNAL_ERROR,
            message: '服务内部错误',
            status: 500,
            request: $request
        );
    }
}
