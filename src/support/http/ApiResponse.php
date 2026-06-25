<?php
declare(strict_types=1);

namespace Z0x\WebmanStarter\Support\Http;

use Webman\Http\Request;
use Webman\Http\Response;

use function json;
use function request;

final class ApiResponse
{
    private const CONTENT_TYPE = 'application/json; charset=utf-8';

    public static function success(mixed $data = null, string $message = 'success', int $status = 200, ?Request $request = null): Response
    {
        return self::json(ErrorCode::SUCCESS, $message, $data, $status, null, $request);
    }

    public static function created(mixed $data = null, string $message = 'success', ?Request $request = null): Response
    {
        return self::success($data, $message, 201, $request);
    }

    public static function deleted(string $message = 'success', ?Request $request = null): Response
    {
        return self::success(null, $message, 200, $request);
    }

    /**
     * @param array<int|string, mixed> $items
     */
    public static function paginated(
        array $items,
        int $page,
        int $perPage,
        int $total,
        string $message = 'success',
        ?Request $request = null
    ): Response {
        $safePerPage = max($perPage, 1);

        return self::success([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $safePerPage),
            ],
        ], $message, 200, $request);
    }

    /**
     * @param array<string, array<int, string>>|null $errors
     */
    public static function fail(
        int $code,
        string $message,
        int $status = 400,
        mixed $data = null,
        ?array $errors = null,
        ?Request $request = null
    ): Response {
        return self::json($code, $message, $data, $status, $errors, $request);
    }

    /**
     * @param array<string, array<int, string>>|null $errors
     */
    private static function json(
        int $code,
        string $message,
        mixed $data,
        int $status,
        ?array $errors,
        ?Request $request
    ): Response {
        $traceId = TraceId::fromRequest($request ?? request());

        $body = [
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'trace_id' => $traceId,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return json($body)
            ->withStatus($status)
            ->header('Content-Type', self::CONTENT_TYPE)
            ->header(TraceId::HEADER, $traceId);
    }
}
