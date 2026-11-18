<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\reset;

use Closure;
use Z0x\WebmanStarter\saas\email\EmailAddress;

interface PasswordResetRepositoryInterface
{
    /** @return array{id:int,email:?string,email_verified_at:?string,email_epoch:string,password_hash:?string}|null */
    public function account(EmailAddress $email): ?array;

    /** @param Closure(?array{id:int,email:?string,email_verified_at:?string,email_epoch:string,password_hash:?string}):void $operation */
    public function withLockedAccount(int $accountId, Closure $operation): void;

    /** Store the password, advance the account session epoch, and audit inside the account transaction. */
    public function reset(int $accountId, #[\SensitiveParameter] string $passwordHash, int $now): void;

    public function auditAttempt(string $action, ?int $accountId, int $now): void;
}
