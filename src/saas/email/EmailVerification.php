<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\email;

use Redis;
use Z0x\WebmanStarter\saas\verification\RatePolicy;
use Z0x\WebmanStarter\saas\verification\RedisVerification;

final class EmailVerification
{
    public const BIND = RedisVerification::EMAIL_BIND;
    public const CHANGE = RedisVerification::EMAIL_CHANGE;
    public const RESET = RedisVerification::EMAIL_RESET;
    public const RECOVERY = RedisVerification::EMAIL_RECOVERY;

    private readonly RedisVerification $challenges;

    public function __construct(Redis $redis, string $prefix, #[\SensitiveParameter] string $secret, RatePolicy $policy)
    {
        $this->challenges = new RedisVerification($redis, $prefix, $secret, $policy, 'email');
    }

    public function reserve(string $email, string $ip, string $purpose, int $now): bool
    {
        return $this->challenges->reserve($email, $ip, $purpose, $now);
    }

    /** @return array{id:string,code:string,replaced:bool} */
    public function issue(string $email, string $purpose, string $flow, string $binding, int $now): array
    {
        return $this->challenges->issue($email, $purpose, $flow, $binding, $now);
    }

    public function verify(string $id, string $email, string $purpose, string $flow, string $binding, #[\SensitiveParameter] string $code, int $now): void
    {
        $this->challenges->verify($id, $email, $purpose, $flow, $binding, $code, $now);
    }
}
