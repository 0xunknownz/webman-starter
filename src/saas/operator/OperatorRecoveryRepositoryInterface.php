<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\operator;

use Closure;

interface OperatorRecoveryRepositoryInterface
{
    /** @template T @param Closure():T $operation @return T */
    public function withGuard(Closure $operation): mixed;

    /** @return array{phone:string,phoneEpoch:string,sessionEpoch:string,hasVerifiedEmail:bool}|null */
    public function lockAccount(int $accountId): ?array;

    public function lockActiveQualificationEpoch(int $accountId): ?string;

    /** Read these under the platform guard, which serializes changes to the set. @return list<int> */
    public function activeAdministratorIds(): array;
}
