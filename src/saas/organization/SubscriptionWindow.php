<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organization;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class SubscriptionWindow
{
    public const INACTIVE = 'inactive';
    public const ACTIVE = 'active';
    public const EXPIRED = 'expired';

    public function __construct(
        public readonly DateTimeImmutable $startsAt,
        public readonly DateTimeImmutable $expiresAt,
    ) {
        if ($expiresAt <= $startsAt) {
            throw self::invalid();
        }
    }

    public static function fromInput(mixed $startsAt, mixed $expiresAt): self
    {
        return new self(self::parse($startsAt), self::parse($expiresAt));
    }

    public function isSameAs(self $other): bool
    {
        return $this->startsAt->getTimestamp() === $other->startsAt->getTimestamp()
            && $this->expiresAt->getTimestamp() === $other->expiresAt->getTimestamp();
    }

    public function statusAt(int $now): string
    {
        $time = new DateTimeImmutable('@' . $now);
        if ($time < $this->startsAt) {
            return self::INACTIVE;
        }

        return $time < $this->expiresAt ? self::ACTIVE : self::EXPIRED;
    }

    private static function parse(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || preg_match(
            '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])\z/i',
            $value,
        ) !== 1) {
            throw self::invalid();
        }
        try {
            $time = new DateTimeImmutable($value);
        } catch (Exception) {
            throw self::invalid();
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw self::invalid();
        }
        $utc = $time->setTimezone(new DateTimeZone('UTC'));
        if ($utc->format('Y') < '1000' || $utc->format('Y') > '9999') {
            throw self::invalid();
        }

        return $utc;
    }

    private static function invalid(): BusinessException
    {
        return new BusinessException('订阅时间无效', 'subscription_window_invalid', 422);
    }
}
