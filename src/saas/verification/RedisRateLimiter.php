<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\verification;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** @internal Channel-neutral limiter for caller-validated targets and fixed purposes. */
final class RedisRateLimiter
{
    private const IP_WINDOW_SECONDS = 3600;

    private const RESERVE = <<<'LUA'
        local now = tonumber(ARGV[1])
        local nextSend = redis.call('GET', KEYS[1])
        local dailyCount = tonumber(redis.call('GET', KEYS[2]) or '0')
        local cutoff = now - tonumber(ARGV[6])
        if (nextSend and tonumber(nextSend) > now) or dailyCount >= tonumber(ARGV[4])
            or redis.call('ZCOUNT', KEYS[3], '(' .. cutoff, '+inf') >= tonumber(ARGV[5]) then
            return 0
        end

        redis.call('SET', KEYS[1], now + tonumber(ARGV[2]), 'EX', ARGV[2])
        redis.call('SET', KEYS[2], dailyCount + 1, 'EX', ARGV[3])
        redis.call('ZREMRANGEBYSCORE', KEYS[3], '-inf', cutoff)
        redis.call('ZADD', KEYS[3], now, ARGV[7])
        redis.call('EXPIRE', KEYS[3], ARGV[6])
        return 1
        LUA;

    private readonly DateTimeZone $timezone;

    public function __construct(
        private readonly RedisVerificationStore $store,
        private readonly RatePolicy $policy,
    ) {
        $this->timezone = new DateTimeZone($policy->timezone);
    }

    /** $ip comes from a trusted connection/proxy resolver. */
    public function reserve(string $channel, string $target, string $ip, string $purpose, int $now): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || ($address = inet_pton($ip)) === false) {
            throw new InvalidArgumentException('Expected a resolved client IP address.');
        }

        // Treat IPv4-mapped IPv6 and the corresponding IPv4 address as one source.
        if (strlen($address) === 16 && str_starts_with($address, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            $address = substr($address, 12);
        }

        $local = (new DateTimeImmutable('@' . $now))->setTimezone($this->timezone);
        $daySeconds = $local->modify('tomorrow')->getTimestamp() - $now;
        $intervalKey = $this->store->key('rate-interval', $channel, $purpose, $target);
        $dailyKey = $this->store->key('rate-day', $channel, $purpose, $target, $local->format('Y-m-d'));
        $ipKey = $this->store->key('rate-ip', $channel, $purpose, $address);

        return $this->store->evaluate(self::RESERVE, [$intervalKey, $dailyKey, $ipKey], [
            $now,
            $this->policy->minIntervalSeconds,
            $daySeconds,
            $this->policy->dailyLimit,
            $this->policy->ipLimit,
            self::IP_WINDOW_SECONDS,
            // A successful reservation can occur only once per target in a given second.
            $this->store->digest('rate-event', $channel, $purpose, $target, (string) $now),
        ]) === 1;
    }
}
