<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\email;

use Closure;

/** @phpstan-type Account array{id:int,email:?string,email_epoch:int,email_verified_at:?string,password_hash:?string} */
interface EmailAccountRepositoryInterface
{
    /** @return Account|null */
    public function account(int $accountId): ?array;

    public function emailOwner(EmailAddress $email): ?int;

    /** @template T @param Closure(?array):T $operation @return T */
    public function withLockedAccount(int $accountId, Closure $operation): mixed;

    /** Bind and audit within the transaction holding the account row lock. */
    public function bind(int $accountId, EmailAddress $email, int $now): void;

    /** Set the hash within the transaction holding the account row lock. */
    public function setPasswordHash(int $accountId, #[\SensitiveParameter] string $hash, int $now): void;

    /** @param array<string,mixed> $metadata */
    public function audit(string $action, int $accountId, array $metadata, int $now): void;
}
