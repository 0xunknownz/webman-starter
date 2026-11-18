<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\domain;

use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class VerificationProof
{
    public const TTL = 600;

    public static function requireCurrent(?int $deadline, int $now): void
    {
        if ($deadline === null || $deadline <= $now) {
            throw new BusinessException('验证凭据已过期或尚未取得', 'credential_expired', 401);
        }
    }
}
