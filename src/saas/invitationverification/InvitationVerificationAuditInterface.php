<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitationverification;

interface InvitationVerificationAuditInterface
{
    /** Record an event using only the invitation ID and a masked destination. */
    public function record(string $action, int $invitationId, string $maskedPhone, int $now): void;
}
