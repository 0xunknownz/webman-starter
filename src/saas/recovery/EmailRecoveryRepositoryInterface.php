<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\recovery;

use Closure;
use Z0x\WebmanStarter\saas\email\EmailAddress;
use Z0x\WebmanStarter\saas\rebind\PhoneChangeFlow;

interface EmailRecoveryRepositoryInterface
{
    /** @return array{id:int,email:?string,email_verified_at:?string,email_epoch:string,phone:string,phone_epoch:string}|null */
    public function account(EmailAddress $email): ?array;

    /**
     * Lock by the original canonical phone, matching the shared phone-change lock order.
     *
     * @template T
     * @param Closure(?array{id:int,email:?string,email_verified_at:?string,email_epoch:string,phone:string,phone_epoch:string}):T $operation
     * @return T
     */
    public function withLockedAccount(string $phone, Closure $operation): mixed;

    /** Attach the credential within the same account transaction that creates the flow. */
    public function storeProof(PhoneChangeFlow $flow, string $emailEpoch, string $tokenHash): void;

    /**
     * Called only by the trusted authorizer inside the shared account/flow transaction.
     *
     * @return array{email_epoch:string, current_email_epoch:string, token_hash:string}|null
     */
    public function lockedProof(PhoneChangeFlow $flow): ?array;

    public function auditAttempt(string $action, ?int $accountId, int $now): void;
}
