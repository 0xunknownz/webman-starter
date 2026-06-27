<?php
declare(strict_types=1);

namespace Z0x\WebmanStarter\Support\Http;

use Ramsey\Uuid\Uuid;
use Webman\Http\Request;

final class TraceId
{
    public const HEADER = 'X-Trace-Id';

    public static function fromRequest(?Request $request): string
    {
        $traceId = $request?->header(self::HEADER);

        if (is_string($traceId) && self::isValid($traceId)) {
            return $traceId;
        }

        return Uuid::uuid7()->toString();
    }

    private static function isValid(string $traceId): bool
    {
        $length = strlen($traceId);

        if ($length < 8 || $length > 128) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._:-]+$/', $traceId) === 1;
    }
}
