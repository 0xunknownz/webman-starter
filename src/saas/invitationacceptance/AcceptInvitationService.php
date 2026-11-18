<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitationacceptance;

use Closure;
use RuntimeException;
use SensitiveParameter;
use Z0x\WebmanStarter\saas\contract\OrganizationAuditInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\invitation\Invitation;
use Z0x\WebmanStarter\saas\invitation\InvitationLifecycle;
use Z0x\WebmanStarter\saas\invitation\InvitationRepositoryInterface;
use Z0x\WebmanStarter\saas\invitationverification\InvitationVerificationService;
use Z0x\WebmanStarter\saas\organization\Organization;
use Z0x\WebmanStarter\saas\organization\OrganizationRepositoryInterface;
use Z0x\WebmanStarter\saas\organization\SubscriptionWindow;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class AcceptInvitationService
{
    /** @param Closure():int $clock */
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly InvitationLifecycle $lifecycle,
        private readonly InvitationRepositoryInterface $invitations,
        private readonly InvitationAcceptanceRepositoryInterface $acceptance,
        private readonly InvitationVerificationService $verification,
        private readonly OrganizationAuditInterface $audit,
        private readonly Closure $clock,
    ) {
    }

    /** @return array{organization_name: string, phone_masked: string, expires_at: string, type: string} */
    public function resolve(#[SensitiveParameter] string $token): array
    {
        $invitation = $this->lifecycle->resolve($token);
        $invitation->requireActive(($this->clock)());
        $organization = $this->organizations->find($invitation->organizationId) ?? throw BusinessException::notFound();

        return [
            'organization_name' => $organization->name,
            'phone_masked' => $invitation->phone->masked(),
            'expires_at' => gmdate(DATE_ATOM, $invitation->expiresAt),
            'type' => $invitation->role->value,
        ];
    }

    public function send(#[SensitiveParameter] string $token, string $phone, string $ip): string
    {
        $invitation = $this->lifecycle->resolve($token);
        $now = ($this->clock)();
        $this->requireVerifiable($invitation, $phone, $now);

        return $this->verification->send($invitation->id, $invitation->phone, $ip, $now);
    }

    /** @return array{credential:string, expires_at:string} */
    public function verify(
        #[SensitiveParameter] string $token,
        string $phone,
        string $challengeId,
        #[SensitiveParameter] string $code,
    ): array {
        $invitation = $this->lifecycle->resolve($token);
        $now = ($this->clock)();
        $this->requireVerifiable($invitation, $phone, $now);
        $proof = $this->verification->verify($invitation->id, $invitation->phone, $challengeId, $code, $now);

        return ['credential' => $proof['credential'], 'expires_at' => gmdate(DATE_ATOM, $proof['expires_at'])];
    }

    /** @return array{organization_id:int, account_id:int, role:string} */
    public function accept(
        #[SensitiveParameter] string $token,
        string $phone,
        #[SensitiveParameter] string $credential,
    ): array {
        $selected = $this->lifecycle->resolve($token);
        // Reject obvious invalid requests before opening a transaction. Recheck after taking the locks.
        self::requireTarget($selected, $phone, ($this->clock)());
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $result = $this->organizations->transaction(fn (): ?array => $this->acceptLocked($selected, $credential));
            if ($result !== null) {
                // Every proof for this invitation is now invalid by SQL state. Redis expires the keys naturally.
                return $result;
            }
        }
        throw new RuntimeException('Could not finish creating an invitation account.');
    }

    /** @return array{organization_id:int, account_id:int, role:string}|null */
    private function acceptLocked(Invitation $selected, #[SensitiveParameter] string $credential): ?array
    {
        // Match the old-phone lock in rebind and the target-phone lock in invitation creation.
        $accountId = $this->organizations->lockAccountByPhone($selected->phone);
        $organization = $this->organizations->find($selected->organizationId, true) ?? throw BusinessException::notFound();
        $invitation = $this->invitations->find($organization->id, $selected->id, true) ?? throw BusinessException::notFound();
        $now = ($this->clock)();
        $invitation->requireActive($now);
        if ($invitation->selector !== $selected->selector || $invitation->phone->e164 !== $selected->phone->e164
            || $invitation->role !== $selected->role || !$invitation->reserved) {
            throw Invitation::invalid();
        }
        $this->verification->requireCredential($invitation->id, $invitation->phone, $credential, $now);
        self::requireOrganizationEligibility($organization, $invitation->role, $now);
        if ($invitation->role === OrganizationRole::Owner && $this->acceptance->hasOwner($organization->id)) {
            throw Invitation::invalid();
        }
        // Acceptance converts an active reservation, so total occupancy must fit without adding another seat.
        if ($organization->seatLimit === null || $this->organizations->seatOccupancy($organization->id, $now) > $organization->seatLimit) {
            throw new BusinessException('组织席位不足', 'seat_limit_exceeded', 409);
        }
        if ($accountId !== null && $this->acceptance->hasMember($organization->id, $accountId)) {
            throw Invitation::invalid();
        }

        $accountId ??= $this->acceptance->createAccount($invitation->phone, $now);
        if ($accountId === null) {
            // No changes preceded the insert. Retry in a new transaction and lock the newly created account first.
            return null;
        }
        $this->acceptance->addMember($organization->id, $accountId, $invitation->role);
        $this->invitations->consume($invitation, $accountId);
        if ($invitation->role === OrganizationRole::Owner) {
            $this->acceptance->activate($organization->id, $now);
        }
        $this->audit->append($organization->id, 'invitation_accepted', $accountId, $accountId, [
            'invitation_id' => $invitation->id,
            'phone_masked' => $invitation->phone->masked(),
            'role' => $invitation->role->value,
            'result' => 'success',
        ], $now);

        return ['organization_id' => $organization->id, 'account_id' => $accountId, 'role' => $invitation->role->value];
    }

    private static function requireTarget(Invitation $invitation, string $input, int $now): void
    {
        $invitation->requireActive($now);
        if (MainlandMobileNumber::fromInput($input)->e164 !== $invitation->phone->e164) {
            throw Invitation::invalid();
        }
    }

    private function requireVerifiable(Invitation $invitation, string $phone, int $now): void
    {
        self::requireTarget($invitation, $phone, $now);
        $organization = $this->organizations->find($invitation->organizationId) ?? throw BusinessException::notFound();
        self::requireOrganizationEligibility($organization, $invitation->role, $now);
    }

    private static function requireOrganizationEligibility(Organization $organization, OrganizationRole $role, int $now): void
    {
        if ($organization->archived) {
            throw new BusinessException('组织已归档', 'organization_archived', 403);
        }
        if ($organization->suspended) {
            throw new BusinessException('组织已停用', 'organization_suspended', 403);
        }
        if (!$organization->activated && $role !== OrganizationRole::Owner) {
            throw new BusinessException('组织尚未激活', 'organization_pending', 403);
        }
        if ($organization->activated && $role !== OrganizationRole::Member) {
            throw Invitation::invalid();
        }
        $status = $organization->subscription->statusAt($now);
        if ($status === SubscriptionWindow::EXPIRED) {
            throw new BusinessException('订阅已到期', 'subscription_expired', 403);
        }
        if ($status === SubscriptionWindow::INACTIVE && $role !== OrganizationRole::Owner) {
            throw new BusinessException('订阅尚未生效', 'subscription_inactive', 403);
        }
    }
}
