<?php
declare(strict_types=1);

namespace Z0x\WebmanStarter\Support\Exception;

use RuntimeException;
use Z0x\WebmanStarter\Support\Http\ErrorCode;

final class BusinessException extends RuntimeException
{
    /**
     * @param array<string, array<int, string>>|null $errors
     */
    public function __construct(
        string $message,
        int $code,
        private readonly int $status = 400,
        private readonly ?array $errors = null,
        private readonly mixed $data = null
    ) {
        parent::__construct($message, $code);
    }

    /**
     * @param array<string, array<int, string>>|null $errors
     */
    public static function make(
        string $message,
        int $code = ErrorCode::PARAMETER_ERROR,
        int $status = 400,
        ?array $errors = null,
        mixed $data = null
    ): self {
        return new self($message, $code, $status, $errors, $data);
    }

    /**
     * @param array<string, array<int, string>>|null $errors
     */
    public static function parameter(string $message = '参数错误', ?array $errors = null): self
    {
        return new self($message, ErrorCode::PARAMETER_ERROR, 400, $errors);
    }

    public static function missingParameter(string $message = '缺少必要参数'): self
    {
        return new self($message, ErrorCode::MISSING_PARAMETER, 400);
    }

    /**
     * @param array<string, array<int, string>> $errors
     */
    public static function validation(array $errors, string $message = '参数验证失败'): self
    {
        return new self($message, ErrorCode::VALIDATION_FAILED, 422, $errors);
    }

    public static function unauthenticated(string $message = '未认证'): self
    {
        return new self($message, ErrorCode::UNAUTHENTICATED, 401);
    }

    public static function tokenMissing(string $message = '缺少认证令牌'): self
    {
        return new self($message, ErrorCode::TOKEN_MISSING, 401);
    }

    public static function tokenInvalid(string $message = '认证令牌无效'): self
    {
        return new self($message, ErrorCode::TOKEN_INVALID, 401);
    }

    public static function tokenExpired(string $message = '认证令牌已过期'): self
    {
        return new self($message, ErrorCode::TOKEN_EXPIRED, 401);
    }

    public static function forbidden(string $message = '无权限'): self
    {
        return new self($message, ErrorCode::FORBIDDEN, 403);
    }

    public static function notFound(string $message = '资源不存在'): self
    {
        return new self($message, ErrorCode::NOT_FOUND, 404);
    }

    public static function unprocessableEntity(string $message = '请求无法处理'): self
    {
        return new self($message, ErrorCode::UNPROCESSABLE_ENTITY, 422);
    }

    public static function conflict(string $message = '业务冲突'): self
    {
        return new self($message, ErrorCode::CONFLICT, 409);
    }

    public static function tooManyRequests(string $message = '请求过于频繁'): self
    {
        return new self($message, ErrorCode::TOO_MANY_REQUESTS, 429);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, array<int, string>>|null
     */
    public function errors(): ?array
    {
        return $this->errors;
    }

    public function data(): mixed
    {
        return $this->data;
    }
}
