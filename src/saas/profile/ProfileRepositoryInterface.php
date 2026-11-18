<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\profile;

interface ProfileRepositoryInterface
{
    /** @return array{account_id:int, display_name:?string}|null */
    public function get(int $accountId): ?array;

    /** @return array{account_id:int, display_name:?string}|null */
    public function update(int $accountId, ?string $displayName): ?array;
}
