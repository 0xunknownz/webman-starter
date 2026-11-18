<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\contract;

use Closure;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;

interface SmsLoginRepositoryInterface
{
    /** @return array{id:int,phone_epoch:int}|null */
    public function account(MainlandMobileNumber $phone): ?array;

    public function hasValidInvitation(MainlandMobileNumber $phone, int $now): bool;

    /** @param Closure(?array{id:int,phone_epoch:int}):array<string,mixed> $operation */
    public function withPhoneAccount(MainlandMobileNumber $phone, Closure $operation): array;

    public function audit(string $action, MainlandMobileNumber $phone, ?int $accountId, int $now): void;
}
