<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\package;

use InvalidArgumentException;
use LengthException;
use Z0x\WebmanStarter\saas\domain\ShortName;
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
        try {
            return ShortName::normalize($name);
        } catch (InvalidArgumentException | LengthException) {
            throw new BusinessException('套餐名称无效', 'package_invalid', 422);
        }
    }

    public static function validateSeatLimit(mixed $seatLimit): int
    {
        if (!is_int($seatLimit) || $seatLimit < 1 || $seatLimit > 100_000) {
            throw new BusinessException('套餐席位无效', 'package_invalid', 422);
        }

        return $seatLimit;
    }
}
