<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\emailchange;

use Closure;
use Z0x\WebmanStarter\saas\email\EmailAddress;
use Z0x\WebmanStarter\saas\session\SessionIdentity;

interface EmailChangeRepositoryInterface
{
    /**
     * Lock and validate the current account and, for platform sessions, its platform qualification.
     *
     * @param Closure(array{email:?string,emailVerified:bool,emailEpoch:string,phone:string,phoneEpoch:string}):mixed $operation
     */
    public function withAccount(SessionIdentity $identity, Closure $operation, int $attempts = 1): mixed;

    /** The account lock must be held before acquiring the flow lock. */
    public function lockFlow(string $id, SessionIdentity $identity): EmailChangeFlow;

    public function create(EmailChangeFlow $flow, int $now): void;

    public function emailTaken(EmailAddress $email, bool $lock = false): bool;

    public function recordProof(EmailChangeFlow $flow, bool $new, int $deadline): void;

    /** Update the locked account and flow and audit the change in the same transaction. */
    public function commitChange(EmailChangeFlow $flow, SessionIdentity $identity, int $now): void;

    public function auditAttempt(string $action, int $accountId, string $purpose, string $channel, int $now): void;
}
