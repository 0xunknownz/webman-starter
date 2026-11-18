<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\session;

use InvalidArgumentException;
use Redis;
use RuntimeException;

final class RedisSessionStore implements SessionStoreInterface
{
    private const ROTATE = <<<'LUA'
        if redis.call('GET', KEYS[1]) ~= ARGV[1] then
            return 0
        end
        if not redis.call('SET', KEYS[1], ARGV[2], 'XX', 'KEEPTTL') then
            return 0
        end
        return 1
        LUA;

    public function __construct(private readonly Redis $redis)
    {
        if (!in_array($redis->getOption(Redis::OPT_PREFIX), [null, false, ''], true)) {
            throw new InvalidArgumentException('Session Redis connection must be unprefixed.');
        }
    }

    public function create(string $key, #[\SensitiveParameter] string $record, int $ttl): void
    {
        if ($this->redis->set($key, $record, ['nx', 'ex' => $ttl]) !== true) {
            throw new RuntimeException('Could not create the session record.');
        }
    }

    public function read(string $key): ?string
    {
        $record = $this->redis->get($key);

        return is_string($record) ? $record : null;
    }

    public function rotate(
        string $key,
        #[\SensitiveParameter] string $expected,
        #[\SensitiveParameter] string $replacement,
    ): bool {
        $result = $this->redis->eval(self::ROTATE, [$key, $expected, $replacement], 1);
        if (!is_int($result)) {
            throw new RuntimeException('Could not rotate the session refresh token.');
        }

        return $result === 1;
    }

    public function delete(string $key): void
    {
        if (!is_int($this->redis->del($key))) {
            throw new RuntimeException('Could not revoke the session.');
        }
    }
}
