<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\verification;

use InvalidArgumentException;
use Redis;
use RuntimeException;

/** @internal */
final class RedisVerificationStore
{
    private readonly string $namespace;

    public function __construct(
        private readonly Redis $redis,
        string $prefix,
        private readonly string $secret,
    ) {
        if (!preg_match('/\A[A-Za-z0-9_.-]+:[A-Za-z0-9_.-]+:?\z/', $prefix)) {
            throw new InvalidArgumentException('Expected a Redis prefix in product:environment format.');
        }

        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('The verification secret must be at least 32 bytes.');
        }

        $clientPrefix = $redis->getOption(Redis::OPT_PREFIX);
        if ($clientPrefix !== null && $clientPrefix !== false && $clientPrefix !== '') {
            throw new InvalidArgumentException('Expected an unprefixed Redis client.');
        }

        $this->namespace = rtrim($prefix, ':') . ':verification:v1:';
    }

    public function key(string $domain, string ...$parts): string
    {
        return $this->namespace . $domain . ':' . $this->digest($domain, ...$parts);
    }

    public function digest(string $domain, string ...$parts): string
    {
        $context = hash_init('sha256', HASH_HMAC, $this->secret);
        foreach ([$this->namespace, $domain, ...$parts] as $part) {
            hash_update($context, pack('N', strlen($part)));
            hash_update($context, $part);
        }

        return hash_final($context);
    }

    /**
     * @param list<string> $keys
     * @param list<int|string> $args
     */
    public function evaluate(string $script, array $keys, array $args): int
    {
        $status = $this->redis->eval($script, [...$keys, ...$args], count($keys));
        if (!is_int($status)) {
            throw new RuntimeException('Redis verification operation failed.');
        }

        return $status;
    }
}
