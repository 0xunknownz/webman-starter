<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\infrastructure;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Z0x\WebmanStarter\saas\contract\SmsLoginRepositoryInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;

final class IlluminateSmsLoginRepository implements SmsLoginRepositoryInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly IlluminatePlatformAdminRepository $audit,
    ) {
    }

    public function account(MainlandMobileNumber $phone): ?array
    {
        return $this->readAccount($phone, false);
    }

    public function hasValidInvitation(MainlandMobileNumber $phone, int $now): bool
    {
        $utc = gmdate('Y-m-d H:i:s', $now);

        return $this->connection->table('starter_invitations as invitation')
            ->join('starter_organizations as org', 'org.id', '=', 'invitation.organization_id')
            ->where('invitation.phone', $phone->e164)
            ->where('invitation.status', 'pending')
            ->where('invitation.reserved', true)
            ->where('invitation.expires_at', '>', $utc)
            ->whereNull('org.suspended_at')
            ->whereNull('org.archived_at')
            ->where('org.subscription_expires_at', '>', $utc)
            ->exists();
    }

    public function withPhoneAccount(MainlandMobileNumber $phone, Closure $operation): array
    {
        return $this->connection->transaction(fn (): array => $operation($this->readAccount($phone, true)));
    }

    public function audit(string $action, MainlandMobileNumber $phone, ?int $accountId, int $now): void
    {
        $this->audit->appendAudit($action, null, $accountId, [
            'phone_masked' => $phone->masked(),
            'purpose' => 'login',
        ], gmdate('Y-m-d H:i:s', $now));
    }

    private function readAccount(MainlandMobileNumber $phone, bool $lock): ?array
    {
        $query = $this->connection->table('starter_accounts')->where('phone', $phone->e164);
        if ($lock) {
            $query->lockForUpdate();
        }
        $account = $query->first(['id', 'phone_epoch']);

        return $account === null ? null : ['id' => (int) $account->id, 'phone_epoch' => (int) $account->phone_epoch];
    }
}
