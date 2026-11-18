<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\session;

use Closure;

interface SessionAccountRepositoryInterface
{
    /**
     * Read current committed state from the primary database. A null platform epoch means there is no active qualification.
     * Epochs are decimal strings so unsigned BIGINT values retain their full precision.
     *
     * @return array{accountEpoch:string,platformEpoch:?string,retainedId?:?string,retainedEpoch?:?string,retainedAt?:?string}|null
     */
    public function currentState(int $accountId, bool $platform): ?array;

    /**
     * Read current state and hold the account row and, for platform sessions, the qualification row locked
     * in that order until the operation finishes. The callback receives null if the account is missing.
     *
     * @template TResult
     * @param Closure(array{accountEpoch:string,platformEpoch:?string,retainedId?:?string,retainedEpoch?:?string,retainedAt?:?string}|null): TResult $operation
     * @return TResult
     */
    public function withLockedState(int $accountId, bool $platform, Closure $operation): mixed;
}
