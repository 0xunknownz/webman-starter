<?php
declare(strict_types=1);

namespace Z0x\WebmanStarter\Support\Http;

final class ErrorCode
{
    public const SUCCESS = 'success';

    public const PARAMETER_ERROR = 'parameter_error';
    public const VALIDATION_FAILED = 'validation_failed';
    public const MISSING_PARAMETER = 'missing_parameter';

    public const UNAUTHENTICATED = 'unauthenticated';
    public const TOKEN_MISSING = 'token_missing';
    public const TOKEN_INVALID = 'token_invalid';
    public const TOKEN_EXPIRED = 'token_expired';

    public const FORBIDDEN = 'forbidden';

    public const NOT_FOUND = 'not_found';
    public const ROUTE_NOT_FOUND = 'route_not_found';

    public const METHOD_NOT_ALLOWED = 'method_not_allowed';

    public const CONFLICT = 'conflict';

    public const PAYLOAD_TOO_LARGE = 'payload_too_large';

    public const UNSUPPORTED_MEDIA_TYPE = 'unsupported_media_type';

    public const UNPROCESSABLE_ENTITY = 'unprocessable_entity';

    public const TOO_MANY_REQUESTS = 'too_many_requests';

    public const INTERNAL_ERROR = 'internal_error';
    public const BAD_GATEWAY = 'bad_gateway';
    public const SERVICE_UNAVAILABLE = 'service_unavailable';
    public const GATEWAY_TIMEOUT = 'gateway_timeout';
}
