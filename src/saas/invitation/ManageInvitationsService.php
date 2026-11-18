<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitation;

use Closure;
use SensitiveParameter;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccess;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccessService;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationOperation;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class ManageInvitationsService
{
    /** @var Closure():int */
    private readonly Closure $clock;

    /** @param (Closure():int)|null $clock */
    public function __construct(
        private readonly OrganizationAccessService $access,
        private readonly SessionService $sessions,
        private readonly InvitationLifecycle $lifecycle,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return array{invitation_id:int, link:string, expires_at:string} */
    public function create(
        SessionIdentity $identity,
        int $organizationId,
        #[SensitiveParameter] mixed $input,
        mixed $role = 'member',
    ): array {
        $operation = OrganizationOperation::MemberInvite;
        $now = ($this->clock)();
        // Authorize before parsing the target. write() then checks access again around its prelock and tenant lock.
        $this->access->require($identity, $organizationId, $operation, $now);
        if ($role === OrganizationRole::Admin->value) {
            throw BusinessException::forbidden();
        }
        if ($role !== OrganizationRole::Member->value) {
            throw BusinessException::validation(['role' => ['邀请角色只能是 member']]);
        }
        if (!is_string($input) || strlen($input) > 250) {
            throw BusinessException::validation(['phone' => ['参数格式无效']]);
        }
        $phone = MainlandMobileNumber::fromInput($input);
        $invitation = $this->access->write($this->sessions, $identity, $organizationId, $operation, $now,
            fn (OrganizationAccess $context): Invitation => $this->lifecycle->create(
                $context->organization, $phone, OrganizationRole::Member, $context->accountId, ($this->clock)(),
            ),
            beforeOrganizationLock: fn (): ?int => $this->lifecycle->lockAccountByPhone($phone),
            clock: $this->clock,
        );

        return $this->lifecycle->linkData($invitation);
    }

    /** @return array{invitation_id:int, link:string, expires_at:string} */
    public function keep(SessionIdentity $identity, int $organizationId, int $invitationId): array
    {
        $invitation = $this->access->write($this->sessions, $identity, $organizationId, OrganizationOperation::InvitationLink,
            ($this->clock)(), fn (OrganizationAccess $context): Invitation => $this->lifecycle->keep(
                $this->memberInvitation($organizationId, $invitationId, true), $context->accountId, ($this->clock)(),
            ), clock: $this->clock);

        return $this->lifecycle->linkData($invitation);
    }

    public function revoke(SessionIdentity $identity, int $organizationId, int $invitationId): void
    {
        $this->access->write($this->sessions, $identity, $organizationId, OrganizationOperation::InvitationRevoke,
            ($this->clock)(), function (OrganizationAccess $context) use ($organizationId, $invitationId): void {
                $this->lifecycle->revoke(
                    $this->memberInvitation($organizationId, $invitationId, true), $context->accountId, ($this->clock)(),
                );
            }, clock: $this->clock);
    }

    /** @return array{invitation_id:int, link:string, expires_at:string} */
    public function reissue(SessionIdentity $identity, int $organizationId, int $invitationId): array
    {
        $operation = OrganizationOperation::InvitationReissue;
        $now = ($this->clock)();
        $this->access->require($identity, $organizationId, $operation, $now);
        // This read only determines the phone to prelock. All mutable invitation state is checked under the lock.
        $phone = $this->memberInvitation($organizationId, $invitationId, false)->phone;
        $invitation = $this->access->write($this->sessions, $identity, $organizationId, $operation, $now,
            function (OrganizationAccess $context) use ($organizationId, $invitationId, $phone): Invitation {
                $old = $this->memberInvitation($organizationId, $invitationId, true);
                if ($old->phone->e164 !== $phone->e164) {
                    throw Invitation::invalid();
                }

                return $this->lifecycle->replace($context->organization, $old, $phone, $context->accountId, ($this->clock)());
            },
            beforeOrganizationLock: fn (): ?int => $this->lifecycle->lockAccountByPhone($phone),
            clock: $this->clock,
        );

        return $this->lifecycle->linkData($invitation);
    }

    private function memberInvitation(int $organizationId, int $invitationId, bool $lock): Invitation
    {
        $invitation = $this->lifecycle->find($organizationId, $invitationId, $lock);
        if ($invitation->role !== OrganizationRole::Member) {
            throw BusinessException::notFound();
        }

        return $invitation;
    }
}
