<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\session;

use Closure;
use DomainException;
use Firebase\JWT\JWT;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Redis;
use RuntimeException;
use stdClass;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class SessionService
{
    public const PURPOSE_PLATFORM = 'platform';
    public const PURPOSE_ORGANIZATION = 'organization';
    private const ACCESS_TTL = 900;
    private const SESSION_TTL = 604800;
    private const JWT_ALGORITHM = 'HS256';
    private const SID_PATTERN = '/\A[0-9a-f]{64}\z/';
    private const REFRESH_TOKEN_PATTERN = '/\A([0-9a-f]{64})\.([0-9a-f]{64})\z/';

    private const ROTATE_REFRESH = <<<'LUA'
        if redis.call('GET', KEYS[1]) ~= ARGV[1] then
            return 0
        end
        if not redis.call('SET', KEYS[1], ARGV[2], 'XX', 'KEEPTTL') then
            return 0
        end
        return 1
        LUA;

    private readonly string $namespace;
    private readonly SessionAccountRepositoryInterface $accounts;

    public function __construct(
        private readonly Redis $redis,
        string $prefix,
        #[\SensitiveParameter] private readonly string $secret,
        ConnectionInterface | SessionAccountRepositoryInterface $accounts,
    ) {
        if (preg_match('/\A[A-Za-z0-9_.-]+:[A-Za-z0-9_.-]+:?\z/', $prefix) !== 1) {
            throw new InvalidArgumentException('Session prefix must contain product and environment segments.');
        }
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('Session secret must be at least 32 bytes.');
        }
        if (!in_array($redis->getOption(Redis::OPT_PREFIX), [null, false, ''], true)) {
            throw new InvalidArgumentException('Session Redis connection must be unprefixed.');
        }

        $this->namespace = rtrim($prefix, ':') . ':session';
        $this->accounts = $accounts instanceof SessionAccountRepositoryInterface
            ? $accounts
            : new IlluminateSessionAccountRepository($accounts);
    }

    /**
     * When called inside a SQL transaction, return the credentials to the client only after it commits.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function issue(int $accountId, string $purpose, int $now): array
    {
        if ($accountId < 1 || !$this->isPurpose($purpose) || $now < 0 || $now > PHP_INT_MAX - self::SESSION_TTL) {
            throw new InvalidArgumentException('Invalid session issuance arguments.');
        }

        $platform = $purpose === self::PURPOSE_PLATFORM;

        return $this->accounts->withLockedState($accountId, $platform, function (?array $state) use (
            $accountId,
            $purpose,
            $platform,
            $now,
        ): array {
            if ($state === null || ($platform && $state['platformEpoch'] === null)) {
                throw BusinessException::forbidden();
            }

            $sid = bin2hex(random_bytes(32));
            $refreshSecret = bin2hex(random_bytes(32));
            $credentials = $this->credentials($sid, $purpose, $refreshSecret, $now, self::ACCESS_TTL);
            $session = json_encode([
                'session_id' => $sid,
                'account_id' => $accountId,
                'purpose' => $purpose,
                'account_epoch' => $state['accountEpoch'],
                'platform_epoch' => $state['platformEpoch'],
                'expires_at' => $now + self::SESSION_TTL,
                'refresh_hash' => hash('sha256', $refreshSecret),
            ], JSON_THROW_ON_ERROR);

            // The session and its lifetime are written together and a SID can never replace an existing session.
            if ($this->redis->set($this->sessionKey($sid), $session, ['nx', 'ex' => self::SESSION_TTL]) !== true) {
                throw new RuntimeException('Could not create the session record.');
            }

            return $credentials;
        });
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function refresh(#[\SensitiveParameter] string $refreshToken, int $now): array
    {
        if ($now < 0 || preg_match(self::REFRESH_TOKEN_PATTERN, $refreshToken, $parts) !== 1) {
            throw BusinessException::tokenInvalid();
        }

        [, $sid, $refreshSecret] = $parts;
        [$encoded, $session] = $this->readSession($sid, $now);
        if (!is_string($session['refresh_hash'] ?? null) || !hash_equals($session['refresh_hash'], hash('sha256', $refreshSecret))) {
            throw BusinessException::tokenInvalid();
        }

        $platform = $session['purpose'] === self::PURPOSE_PLATFORM;

        return $this->accounts->withLockedState($session['account_id'], $platform, function (?array $state) use (
            $sid,
            $encoded,
            $session,
            $platform,
            $now,
        ): array {
            $this->requireCurrentState($state, $session, $platform);
            $newSecret = bin2hex(random_bytes(32));
            $accessTtl = min(self::ACCESS_TTL, $session['expires_at'] - $now);
            $credentials = $this->credentials($sid, $session['purpose'], $newSecret, $now, $accessTtl);
            $session['refresh_hash'] = hash('sha256', $newSecret);

            // Keep the original Redis expiry and compare the exact record read before taking the SQL locks.
            // A concurrent rotation changes that record. Logout or Redis expiry removes it entirely.
            $result = $this->redis->eval(self::ROTATE_REFRESH, [
                $this->sessionKey($sid), $encoded, json_encode($session, JSON_THROW_ON_ERROR),
            ], 1);
            if (!is_int($result)) {
                throw new RuntimeException('Could not rotate the session refresh token.');
            }
            if ($result !== 1) {
                throw BusinessException::tokenInvalid();
            }

            return $credentials;
        });
    }

    /** Revoke the session represented by an authenticated identity. */
    public function logout(SessionIdentity $identity): void
    {
        if (preg_match(self::SID_PATTERN, $identity->sessionId) !== 1) {
            throw new InvalidArgumentException('Invalid session identity.');
        }
        if (!is_int($this->redis->del($this->sessionKey($identity->sessionId)))) {
            throw new RuntimeException('Could not revoke the session.');
        }
    }

    public function authenticate(#[\SensitiveParameter] string $token, ?string $expectedPurpose, int $now): SessionIdentity
    {
        if ($expectedPurpose !== null && !$this->isPurpose($expectedPurpose)) {
            throw new InvalidArgumentException('Invalid expected session purpose.');
        }

        $claims = $this->verifyAccessToken($token, $now);
        if ($expectedPurpose !== null && $claims->purpose !== $expectedPurpose) {
            throw new BusinessException('认证令牌用途不匹配', 'token_purpose_mismatch', 403);
        }

        [, $session] = $this->readSession($claims->sid, $now);
        if ($session['purpose'] !== $claims->purpose) {
            throw BusinessException::tokenInvalid();
        }

        $platform = $claims->purpose === self::PURPOSE_PLATFORM;
        $state = $this->accounts->currentState($session['account_id'], $platform);
        $this->requireCurrentState($state, $session, $platform);

        return new SessionIdentity(
            $session['account_id'], $claims->sid, $claims->purpose, $state['accountEpoch'], $state['platformEpoch'],
            $session['account_epoch'],
        );
    }

    /** @template T @param Closure():T $operation @return T */
    public function withCurrentIdentity(SessionIdentity $identity, int $now, Closure $operation): mixed
    {
        $platform = $identity->purpose === self::PURPOSE_PLATFORM;

        return $this->accounts->withLockedState($identity->accountId, $platform, function (?array $state) use ($identity, $now, $platform, $operation): mixed {
            [, $session] = $this->readSession($identity->sessionId, $now);
            if ($session['account_id'] !== $identity->accountId || $session['purpose'] !== $identity->purpose
                || ($session['account_epoch'] ?? null) !== $identity->issuedAccountEpoch
                || ($state['accountEpoch'] ?? null) !== $identity->accountEpoch
                || ($state['platformEpoch'] ?? null) !== $identity->platformEpoch) {
                throw BusinessException::tokenInvalid();
            }
            $this->requireCurrentState($state, $session, $platform);

            return $operation();
        });
    }

    /** @return array{string, array{session_id: string, account_id: int, purpose: string, expires_at: int, account_epoch?: string, platform_epoch?: ?string, refresh_hash?: string}} */
    private function readSession(string $sid, int $now): array
    {
        $encoded = $this->redis->get($this->sessionKey($sid));
        $session = is_string($encoded) ? json_decode($encoded, true) : null;
        if (
            !is_array($session)
            || ($session['session_id'] ?? null) !== $sid
            || !is_int($session['account_id'] ?? null) || $session['account_id'] < 1
            || !is_string($session['purpose'] ?? null) || !$this->isPurpose($session['purpose'])
            || !is_int($session['expires_at'] ?? null) || $session['expires_at'] <= $now
        ) {
            throw BusinessException::tokenInvalid();
        }

        return [$encoded, $session];
    }

    /**
     * @param array{accountEpoch:string,platformEpoch:?string,retainedId?:?string,retainedEpoch?:?string,retainedAt?:?string}|null $state
     * @param array{session_id:string,account_epoch?:string,platform_epoch?:?string} $session
     */
    private function requireCurrentState(?array $state, array $session, bool $platform): void
    {
        if ($state === null) {
            throw BusinessException::tokenInvalid();
        }
        $issuedEpoch = $session['account_epoch'] ?? null;
        $retained = ($state['retainedAt'] ?? null) === $state['accountEpoch']
            && ($state['retainedId'] ?? null) === $session['session_id']
            && ($state['retainedEpoch'] ?? null) === $issuedEpoch;
        if ($state['accountEpoch'] !== $issuedEpoch && !$retained) {
            throw BusinessException::tokenInvalid();
        }
        if ($platform) {
            if ($state['platformEpoch'] === null) {
                throw BusinessException::forbidden();
            }
            if ($state['platformEpoch'] !== ($session['platform_epoch'] ?? null)) {
                throw BusinessException::tokenInvalid();
            }
        }
    }

    private function sessionKey(string $sid): string
    {
        return $this->namespace . ':' . $sid;
    }

    /** @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int} */
    private function credentials(string $sid, string $purpose, #[\SensitiveParameter] string $refreshSecret, int $now, int $accessTtl): array
    {
        return [
            'access_token' => JWT::encode([
                'iss' => $this->namespace,
                'sid' => $sid,
                'purpose' => $purpose,
                'iat' => $now,
                'exp' => $now + $accessTtl,
            ], $this->secret, self::JWT_ALGORITHM),
            'refresh_token' => $sid . '.' . $refreshSecret,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
        ];
    }

    private function verifyAccessToken(#[\SensitiveParameter] string $token, int $now): stdClass
    {
        if (strlen($token) > 4096 || preg_match('/\A([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})\z/', $token, $parts) !== 1) {
            throw BusinessException::tokenInvalid();
        }
        [, $header, $payload, $signature] = $parts;

        // JWT::decode reads a process-wide clock. Verify the pinned HMAC via Firebase and check time locally.
        $expectedSignature = JWT::urlsafeB64Encode(JWT::sign($header . '.' . $payload, $this->secret, self::JWT_ALGORITHM));
        if (!hash_equals($expectedSignature, $signature)) {
            throw BusinessException::tokenInvalid();
        }
        try {
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($header));
            $claims = JWT::jsonDecode(JWT::urlsafeB64Decode($payload));
        } catch (DomainException) {
            throw BusinessException::tokenInvalid();
        }

        if (
            !$header instanceof stdClass || ($header->alg ?? null) !== self::JWT_ALGORITHM || ($header->typ ?? null) !== 'JWT'
            || !$claims instanceof stdClass || ($claims->iss ?? null) !== $this->namespace
            || !is_string($claims->sid ?? null) || preg_match(self::SID_PATTERN, $claims->sid) !== 1
            || !is_string($claims->purpose ?? null) || !$this->isPurpose($claims->purpose)
            || !is_int($claims->iat ?? null) || $claims->iat < 0 || $claims->iat > $now
            || (property_exists($claims, 'nbf') && (!is_int($claims->nbf) || $claims->nbf > $now))
            || !is_int($claims->exp ?? null) || $claims->exp <= $claims->iat || $claims->exp - $claims->iat > self::ACCESS_TTL
        ) {
            throw BusinessException::tokenInvalid();
        }
        if ($claims->exp <= $now) {
            throw BusinessException::tokenExpired();
        }

        return $claims;
    }

    private function isPurpose(string $purpose): bool
    {
        return $purpose === self::PURPOSE_PLATFORM || $purpose === self::PURPOSE_ORGANIZATION;
    }
}
