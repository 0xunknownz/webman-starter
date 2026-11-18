<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitation;

use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class Invitation
{
    public const PENDING = 'pending';
    public const REVOKED = 'revoked';
    public const ACCEPTED = 'accepted';
    public const LINK_LIFETIME = 7 * 24 * 60 * 60;

    public function __construct(
        public readonly int $id,
        public readonly int $organizationId,
        public readonly MainlandMobileNumber $phone,
        public readonly OrganizationRole $role,
        public readonly ?string $selector,
        public readonly int $expiresAt,
        public readonly string $status,
        public readonly bool $reserved,
        public readonly ?int $replacedAt,
    ) {
    }

    public function requireActive(int $now): void
    {
        if ($this->status !== self::PENDING || $this->expiresAt <= $now) {
            throw self::invalid();
        }
    }

    public static function invalid(): BusinessException
    {
        return new BusinessException('邀请已失效', 'invitation_invalid', 409);
    }
}
