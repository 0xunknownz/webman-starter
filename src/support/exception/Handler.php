<?php
declare(strict_types=1);

namespace Z0x\WebmanStarter\Support\Exception;

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
                errors: $exception->errors(),
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
