<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\verification;

use DateTimeZone;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class RatePolicy
{
    public readonly int $minIntervalSeconds;
    public readonly int $dailyLimit;
    public readonly int $ipLimit;
    public readonly int $ttlSeconds;
    public readonly int $maxAttempts;
    public readonly string $timezone;

    /** Configuration values must be integers, including when called from non-strict PHP. */
    public function __construct(
        mixed $minIntervalSeconds = 60,
        mixed $dailyLimit = 10,
        mixed $ipLimit = 20,
        mixed $ttlSeconds = 300,
        mixed $maxAttempts = 5,
        mixed $timezone = 'Asia/Shanghai',
        public readonly string $channel = 'sms',
    ) {
        if (!in_array($this->channel, ['sms', 'email'], true)) {
            throw self::invalid();
        }

        $this->minIntervalSeconds = self::boundedInteger($minIntervalSeconds, 60, 86400);
        $this->dailyLimit = self::boundedInteger($dailyLimit, 1, 10);
        $this->ipLimit = self::boundedInteger($ipLimit, 1, 200);
        $this->ttlSeconds = self::boundedInteger($ttlSeconds, 1, $this->channel === 'email' ? 600 : 300);
        $this->maxAttempts = self::boundedInteger($maxAttempts, 1, 5);

        if (!is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw self::invalid();
        }

        $this->timezone = $timezone;
    }

    public static function email(
        mixed $minIntervalSeconds = 60,
        mixed $dailyLimit = 10,
        mixed $ipLimit = 20,
        mixed $ttlSeconds = 600,
        mixed $maxAttempts = 5,
        mixed $timezone = 'Asia/Shanghai',
    ): self {
        return new self($minIntervalSeconds, $dailyLimit, $ipLimit, $ttlSeconds, $maxAttempts, $timezone, 'email');
    }

    private static function boundedInteger(mixed $value, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw self::invalid();
        }

        return $value;
    }

    private static function invalid(): BusinessException
    {
        return new BusinessException('rate_limit_config_invalid', 'rate_limit_config_invalid', 500);
    }
}
