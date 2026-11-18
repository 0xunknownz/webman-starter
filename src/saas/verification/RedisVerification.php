<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\verification;

use Redis;
use RuntimeException;
use SensitiveParameter;
use Z0x\WebmanStarter\saas\domain\VerificationProof;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

/** Redis-backed challenges for one configured channel, product, and environment. */
final class RedisVerification
{
    public const LOGIN = 'login';
    public const INVITATION = 'invitation';
    public const REBIND = 'rebind';
    public const ACCOUNT_SECURITY = 'account_security';
    public const EMAIL_BIND = 'bind';
    public const EMAIL_CHANGE = 'change';
    public const EMAIL_RESET = 'reset';
    public const EMAIL_RECOVERY = 'recovery';

    private const ISSUE = <<<'LUA'
        local now = tonumber(ARGV[1])
        local expiresAt = now + tonumber(ARGV[2])
        local current = redis.call('HMGET', KEYS[1], 'issuedAt', 'expiresAt', 'active')
        if current[1] and tonumber(current[1]) > now then
            return -1
        end
        if redis.call('EXISTS', KEYS[2]) == 1 then
            return -2
        end

        redis.call('HSET', KEYS[2], 'scope', KEYS[1], 'expiresAt', expiresAt, 'proof', ARGV[3],
            'failures', 0, 'maxAttempts', ARGV[4])
        redis.call('EXPIRE', KEYS[2], ARGV[2])
        redis.call('HSET', KEYS[1], 'latest', KEYS[2], 'issuedAt', now, 'expiresAt', expiresAt, 'active', 1)
        redis.call('EXPIRE', KEYS[1], ARGV[2])
        if current[3] == '1' and tonumber(current[2]) > now then
            return 1
        end
        return 0
        LUA;

    private const VERIFY = <<<'LUA'
        local challenge = redis.call('HMGET', KEYS[2], 'scope', 'expiresAt', 'proof', 'failures', 'maxAttempts')
        if not challenge[1] or tonumber(challenge[2]) <= tonumber(ARGV[1]) then
            return 1
        end
        if challenge[1] ~= KEYS[1] then
            return 2
        end
        if redis.call('HGET', KEYS[1], 'latest') ~= KEYS[2] then
            return 1
        end
        local maxAttempts = math.min(tonumber(challenge[5]), tonumber(ARGV[3]))
        if tonumber(challenge[4]) >= maxAttempts then
            redis.call('HSET', KEYS[1], 'active', 0)
            redis.call('HSET', KEYS[2], 'maxAttempts', maxAttempts)
            return 1
        end

        -- Compare fixed-length HMACs without stopping at the first different byte.
        local difference = 0
        for i = 1, 64 do
            difference = bit.bor(difference, bit.bxor(string.byte(challenge[3], i), string.byte(ARGV[2], i)))
        end
        if difference ~= 0 then
            if redis.call('HINCRBY', KEYS[2], 'failures', 1) == maxAttempts then
                redis.call('HSET', KEYS[1], 'active', 0)
                redis.call('HSET', KEYS[2], 'maxAttempts', maxAttempts)
                return 4
            end
            return 3
        end

        if KEYS[3] then
            local expiresAt = tonumber(ARGV[1]) + tonumber(ARGV[4])
            if redis.call('EXISTS', KEYS[3]) == 1 then
                return -2
            end
            redis.call('HSET', KEYS[3], 'scope', KEYS[1], 'expiresAt', expiresAt)
            redis.call('EXPIRE', KEYS[3], ARGV[4])
        end
        redis.call('DEL', KEYS[2])
        -- Keep the issue time until native expiry to reject an older delayed issue.
        redis.call('HSET', KEYS[1], 'active', 0)
        return 0
        LUA;

    private const REQUIRE_PROOF = <<<'LUA'
        local proof = redis.call('HMGET', KEYS[1], 'scope', 'expiresAt')
        if not proof[1] or tonumber(proof[2]) <= tonumber(ARGV[2]) then
            return 1
        end
        if proof[1] ~= ARGV[1] then
            return 2
        end
        return 0
        LUA;

    private const FORGET_PROOF = <<<'LUA'
        if redis.call('HGET', KEYS[1], 'scope') == ARGV[1] then
            return redis.call('DEL', KEYS[1])
        end
        return 0
        LUA;

    private readonly RedisVerificationStore $store;
    private readonly RedisRateLimiter $limiter;

    /** Supply a dedicated, unprefixed client and a stable, secret key for the product/environment. */
    public function __construct(
        Redis $redis,
        string $prefix,
        string $secret,
        private readonly RatePolicy $policy,
        private readonly string $channel = 'sms',
    ) {
        if ($policy->channel !== $channel) {
            throw new BusinessException('rate_limit_config_invalid', 'rate_limit_config_invalid', 500);
        }
        $this->store = new RedisVerificationStore($redis, $prefix, $secret);
        $this->limiter = new RedisRateLimiter($this->store, $policy);
    }

    /** $target is canonical E.164 or a normalized email. $ip is trusted and $now is Unix seconds. */
    public function reserve(string $target, string $ip, string $purpose, int $now): bool
    {
        $this->requireTarget($target);
        $this->requirePurpose($purpose);

        return $this->limiter->reserve($this->channel, $target, $ip, $purpose, $now);
    }

    /**
     * Call only after a successful reservation and a decision to issue. Send the code through the trusted delivery adapter.
     * The ID is 32 lowercase hex digits. Equal issue times use Redis commit order; an older logical issue time
     * is rejected while the newer scope marker still exists.
     *
     * @return array{id: string, code: string, replaced: bool}
     */
    public function issue(string $target, string $purpose, string $flow, string $binding, int $now): array
    {
        $this->requireTarget($target);
        $this->requirePurpose($purpose);

        $id = bin2hex(random_bytes(16));
        $code = sprintf('%06d', random_int(0, 999999));
        $scopeKey = $this->scopeKey($target, $purpose, $flow, $binding);
        $status = $this->store->evaluate(self::ISSUE, [$scopeKey, $this->store->key($this->otpDomain('otp-id'), $id)], [
            $now,
            $this->policy->ttlSeconds,
            $this->store->digest('otp-proof', $scopeKey, $id, $code),
            $this->policy->maxAttempts,
        ]);

        return match ($status) {
            0, 1 => ['id' => $id, 'code' => $code, 'replaced' => $status === 1],
            -1 => throw self::expired(),
            default => throw new RuntimeException('Could not issue a verification challenge.'),
        };
    }

    public function verify(string $id, string $target, string $purpose, string $flow, string $binding, #[SensitiveParameter] string $code, int $now): void
    {
        $this->verifyChallenge($id, $target, $purpose, $flow, $binding, $code, $now);
    }

    /**
     * Consume the challenge and mint a scoped short-term proof in one Redis operation.
     *
     * @internal
     * @return array{credential: string, expires_at: int}
     */
    public function verifyAndIssueProof(
        string $id,
        string $target,
        string $purpose,
        string $flow,
        string $binding,
        #[SensitiveParameter] string $code,
        int $now,
    ): array {
        $credential = bin2hex(random_bytes(32));
        $this->verifyChallenge($id, $target, $purpose, $flow, $binding, $code, $now, $this->proofKey($credential));

        return ['credential' => $credential, 'expires_at' => $now + VerificationProof::TTL];
    }

    /** @internal Read the proof without consuming it or changing its deadline. */
    public function requireProof(
        string $target,
        string $purpose,
        string $flow,
        string $binding,
        #[SensitiveParameter] string $credential,
        int $now,
    ): void {
        if (!preg_match('/\A[0-9a-f]{64}\z/', $credential)) {
            throw self::expired();
        }
        $status = $this->store->evaluate(self::REQUIRE_PROOF, [$this->proofKey($credential)], [
            $this->scopeKey($target, $purpose, $flow, $binding), $now,
        ]);
        match ($status) {
            0 => null,
            1 => throw self::expired(),
            2 => throw self::mismatch(),
            default => throw new RuntimeException('Could not check the verification proof.'),
        };
    }

    /** @internal Remove the given proof only if it belongs to this scope. */
    public function forgetProof(string $target, string $purpose, string $flow, string $binding, #[SensitiveParameter] string $credential): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $credential)) {
            $this->store->evaluate(self::FORGET_PROOF, [$this->proofKey($credential)], [
                $this->scopeKey($target, $purpose, $flow, $binding),
            ]);
        }
    }

    private function verifyChallenge(
        string $id,
        string $target,
        string $purpose,
        string $flow,
        string $binding,
        #[SensitiveParameter] string $code,
        int $now,
        ?string $proofKey = null,
    ): void {
        if (!preg_match('/\A[0-9a-f]{32}\z/', $id)) {
            throw self::expired();
        }
        $this->requirePurpose($purpose);

        $scopeKey = $this->scopeKey($target, $purpose, $flow, $binding);
        $keys = [$scopeKey, $this->store->key($this->otpDomain('otp-id'), $id)];
        if ($proofKey !== null) {
            $keys[] = $proofKey;
        }
        $status = $this->store->evaluate(self::VERIFY, $keys, [
            $now,
            $this->store->digest('otp-proof', $scopeKey, $id, $code),
            $this->policy->maxAttempts,
            VerificationProof::TTL,
        ]);

        match ($status) {
            0 => null,
            1 => throw self::expired(),
            2 => throw self::mismatch(),
            3 => throw new BusinessException('Verification code is invalid.', 'verification_code_invalid', 401),
            4 => throw new BusinessException('Verification attempts exceeded.', 'verification_attempts_exceeded', 401),
            default => throw new RuntimeException('Could not verify the challenge.'),
        };
    }

    private function scopeKey(string $target, string $purpose, string $flow, string $binding): string
    {
        return $this->store->key($this->otpDomain('otp-scope'), $target, $purpose, $flow, $binding);
    }

    private function proofKey(#[SensitiveParameter] string $credential): string
    {
        return $this->store->key($this->otpDomain('verified-proof'), $credential);
    }

    private function otpDomain(string $domain): string
    {
        return $this->channel === 'sms' ? $domain : $this->channel . '-' . $domain;
    }

    private function requireTarget(string $target): void
    {
        if ($this->channel === 'email') {
            if (strlen($target) > 254 || strtolower($target) !== $target || preg_match('/[\x00-\x1F\x7F]/', $target) === 1
                || filter_var($target, FILTER_VALIDATE_EMAIL) === false) {
                throw new BusinessException('邮箱格式无效', 'email_invalid', 422);
            }

            return;
        }
        if (!preg_match('/\A\+[1-9][0-9]{1,14}\z/', $target)) {
            throw new BusinessException('Expected a canonical E.164 number.', 'phone_invalid', 422);
        }
    }

    private function requirePurpose(string $purpose): void
    {
        $purposes = $this->channel === 'email'
            ? [self::EMAIL_BIND, self::EMAIL_CHANGE, self::EMAIL_RESET, self::EMAIL_RECOVERY]
            : [self::LOGIN, self::INVITATION, self::REBIND, self::ACCOUNT_SECURITY];
        if (!in_array($purpose, $purposes, true)) {
            throw self::mismatch();
        }
    }

    private static function expired(): BusinessException
    {
        return new BusinessException('Verification challenge has expired or is no longer available.', 'credential_expired', 401);
    }

    private static function mismatch(): BusinessException
    {
        return new BusinessException('Verification challenge does not match this flow.', 'purpose_mismatch', 401);
    }
}
