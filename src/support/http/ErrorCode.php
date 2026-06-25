<?php
declare(strict_types=1);

namespace Z0x\WebmanStarter\Support\Http;

final class ErrorCode
{
    public const SUCCESS = 0;

    public const PARAMETER_ERROR = 40001;
    public const VALIDATION_FAILED = 40002;
    public const MISSING_PARAMETER = 40003;

    public const UNAUTHENTICATED = 40101;
    public const TOKEN_MISSING = 40102;
    public const TOKEN_INVALID = 40103;
    public const TOKEN_EXPIRED = 40104;

    public const FORBIDDEN = 40301;

    public const NOT_FOUND = 40401;
    public const ROUTE_NOT_FOUND = 40402;

    public const METHOD_NOT_ALLOWED = 40501;

    public const CONFLICT = 40901;

    public const PAYLOAD_TOO_LARGE = 41301;

    public const UNSUPPORTED_MEDIA_TYPE = 41501;

    public const UNPROCESSABLE_ENTITY = 42201;

    public const TOO_MANY_REQUESTS = 42901;

    public const INTERNAL_ERROR = 50001;
    public const BAD_GATEWAY = 50201;
    public const SERVICE_UNAVAILABLE = 50301;
    public const GATEWAY_TIMEOUT = 50401;
}
