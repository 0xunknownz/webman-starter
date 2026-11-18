<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\session;

interface SessionStoreInterface
{
    /** Create a record with its expiry, without replacing an existing record. */
    public function create(string $key, #[\SensitiveParameter] string $record, int $ttl): void;

    public function read(string $key): ?string;

    /** Replace only the expected record, keeping its expiry. Return false if it changed or expired. */
    public function rotate(
        string $key,
        #[\SensitiveParameter] string $expected,
        #[\SensitiveParameter] string $replacement,
    ): bool;

    public function delete(string $key): void;
}
