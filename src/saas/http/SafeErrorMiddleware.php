<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\http;

use support\Log;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;
use Z0x\WebmanStarter\Support\Http\ErrorCode;

final class SafeErrorMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            return $handler($request);
        } catch (BusinessException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $response = ApiResponse::fail(ErrorCode::INTERNAL_ERROR, '服务内部错误', 500, request: $request);
            // SQL bindings, provider exceptions and request URLs may contain credentials or identifiers.
            Log::error('Webman Starter request failed', [
                'exception_type' => $exception::class,
                'trace_id' => $response->getHeader('X-Trace-Id'),
            ]);

            return $response->header('Cache-Control', 'no-store');
        }
    }
}
