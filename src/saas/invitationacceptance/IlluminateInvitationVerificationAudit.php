<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitationacceptance;

use Illuminate\Database\ConnectionInterface;
use Z0x\WebmanStarter\saas\contract\OrganizationAuditInterface;
use Z0x\WebmanStarter\saas\invitationverification\InvitationVerificationAuditInterface;

final class IlluminateInvitationVerificationAudit implements InvitationVerificationAuditInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly OrganizationAuditInterface $audit,
    ) {
    }

    public function record(string $action, int $invitationId, string $maskedPhone, int $now): void
    {
        $organizationId = $this->connection->table('starter_invitations')->useWritePdo()
            ->where('id', $invitationId)->value('organization_id');
        if ($organizationId !== null) {
            $this->audit->append((int) $organizationId, $action, null, null, [
                'invitation_id' => $invitationId,
                'phone_masked' => $maskedPhone,
                'purpose' => 'invitation',
            ], $now);
        }
    }
}
