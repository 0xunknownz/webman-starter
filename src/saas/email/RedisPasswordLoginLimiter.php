<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\email;

use Redis;
use Z0x\WebmanStarter\saas\verification\RedisVerificationStore;

final class RedisPasswordLoginLimiter
{
    private const MAX_FAILURES = 5;
    private const LOCK_SECONDS = 900;
    private const UPDATE = <<<'LUA'
        local now = tonumber(ARGV[1])
        local lockedUntil = redis.call('HGET', KEYS[1], 'lockedUntil')
        if lockedUntil then
            if tonumber(lockedUntil) > now then
                return 1
            end
            redis.call('DEL', KEYS[1])
        end
        if ARGV[2] == 'success' then
            redis.call('DEL', KEYS[1])
        elseif ARGV[2] == 'failure' and redis.call('HINCRBY', KEYS[1], 'failures', 1) >= tonumber(ARGV[3]) then
            redis.call('HSET', KEYS[1], 'lockedUntil', now + tonumber(ARGV[4]))
            redis.call('EXPIRE', KEYS[1], ARGV[4])
            return 1
        end
        return 0
        LUA;

    private readonly RedisVerificationStore $store;

    public function __construct(Redis $redis, string $prefix, #[\SensitiveParameter] string $secret)
    {
        $this->store = new RedisVerificationStore($redis, $prefix, $secret);
    }

    public function locked(int $accountId, int $now): bool
    {
        return $this->update($accountId, $now, 'check');
    }

    public function record(int $accountId, bool $valid, int $now): bool
    {
        return $this->update($accountId, $now, $valid ? 'success' : 'failure');
    }

    private function update(int $accountId, int $now, string $operation): bool
    {
        return $this->store->evaluate(self::UPDATE, [$this->store->key('password-login', (string) $accountId)], [
            $now,
            $operation,
            self::MAX_FAILURES,
            self::LOCK_SECONDS,
        ]) === 1;
    }
}
