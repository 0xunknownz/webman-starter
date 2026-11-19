<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\package;

use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class PackageTemplate
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $seatLimit,
        public readonly bool $retired,
    ) {
    }

    public function requireAvailable(): self
    {
        if ($this->retired) {
            throw new BusinessException('套餐已停用', 'package_retired', 409);
        }

        return new self($this->id, self::normalizeName($this->name), self::validateSeatLimit($this->seatLimit), false);
    }

    public static function normalizeName(mixed $name): string
    {
        // Match the display name rules. Reject malformed UTF-8 and controls before trimming.
        if (!is_string($name) || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $name) !== 0) {
            throw new BusinessException('套餐名称无效', 'package_invalid', 422);
        }
        $trimmed = preg_replace('/\A\p{Zs}+|\p{Zs}+\z/u', '', $name);
        if ($trimmed === null || preg_match('/\A.{1,50}\z/u', $trimmed) !== 1) {
            throw new BusinessException('套餐名称无效', 'package_invalid', 422);
        }

        return $trimmed;
    }

    public static function validateSeatLimit(mixed $seatLimit): int
    {
        if (!is_int($seatLimit) || $seatLimit < 1 || $seatLimit > 100_000) {
            throw new BusinessException('套餐席位无效', 'package_invalid', 422);
        }

        return $seatLimit;
    }
}
