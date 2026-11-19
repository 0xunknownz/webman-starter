<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\domain;

use InvalidArgumentException;
use LengthException;

final class ShortName
{
    public static function normalize(mixed $value): string
    {
        if (!is_string($value) || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) !== 0) {
            throw new InvalidArgumentException('Name must be valid UTF-8 without control or line separator characters.');
        }

        $name = preg_replace('/\A\p{Zs}+|\p{Zs}+\z/u', '', $value);
        if ($name === null || preg_match('/\A.{1,50}\z/u', $name) !== 1) {
            throw new LengthException('Name must contain 1–50 Unicode characters after trimming.');
        }

        return $name;
    }
}
