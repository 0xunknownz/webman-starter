<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitation;

use InvalidArgumentException;
use SensitiveParameter;
use Z0x\WebmanStarter\saas\contract\OrganizationAuditInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\organization\Organization;
use Z0x\WebmanStarter\saas\organization\OrganizationRepositoryInterface;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

/**
 * Shared ordinary-member and first-owner invitation mutations. The caller checks its actor and organization
 * policy, opens the organization transaction, prelocks target accounts in phone order, and locks the organization.
 * Load any invite passed to keep, revoke or replace under that lock. The repositories and audit share the connection.
 */
final class InvitationLifecycle
{
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly InvitationRepositoryInterface $invitations,
        private readonly ?InvitationLink $links,
        private readonly OrganizationAuditInterface $audit,
    ) {
    }

    public function find(int $organizationId, int $id, bool $lock = false): Invitation
    {
        return $this->invitations->find($organizationId, $id, $lock) ?? throw BusinessException::notFound();
    }

    /** Returns recognized historical rows as well; callers check activity and authorization independently. */
    public function resolve(#[SensitiveParameter] string $token): Invitation
    {
        return $this->invitations->findBySelector($this->requireLinks()->selector($token)) ?? throw BusinessException::notFound();
    }

    public function lockAccountByPhone(MainlandMobileNumber $phone): ?int
    {
        return $this->organizations->lockAccountByPhone($phone);
    }

    public function create(Organization $organization, MainlandMobileNumber $phone, OrganizationRole $role, int $actorId, int $now): Invitation
    {
        if ($role === OrganizationRole::Admin) {
            throw new InvalidArgumentException('Invitations cannot grant the administrator role.');
        }
        $this->organizations->assertInvitationCapacity($organization, $phone, $now);
        $invitation = $this->invitations->insert($organization->id, $phone, $role, $now + Invitation::LINK_LIFETIME);
        $this->audit('invitation_created', $invitation, $actorId, $now);

        return $invitation;
    }

    public function keep(Invitation $invitation, int $actorId, int $now): Invitation
    {
        $invitation->requireActive($now);
        if ($invitation->selector === null) {
            throw Invitation::invalid();
        }
        $this->audit('invitation_link_retrieved', $invitation, $actorId, $now);

        return $invitation;
    }

    public function revoke(Invitation $invitation, int $actorId, int $now): void
    {
        $this->invitations->revoke($invitation);
        $this->audit('invitation_revoked', $invitation, $actorId, $now);
    }

    public function replace(Organization $organization, Invitation $old, MainlandMobileNumber $phone, int $actorId, int $now): Invitation
    {
        if ($old->organizationId !== $organization->id) {
            throw BusinessException::notFound();
        }
        if ($old->replacedAt !== null) {
            throw Invitation::invalid();
        }
        $this->organizations->assertInvitationCapacity($organization, $phone, $now, $old->id);
        $this->invitations->markReplaced($old, $now);
        $invitation = $this->invitations->insert($organization->id, $phone, $old->role, $now + Invitation::LINK_LIFETIME);
        $this->audit('invitation_reissued', $invitation, $actorId, $now, [
            'previous_invitation_id' => $old->id,
        ]);

        return $invitation;
    }

    /** @return array{invitation_id:int, link:string, expires_at:string} */
    public function linkData(Invitation $invitation): array
    {
        return [
            'invitation_id' => $invitation->id,
            'link' => $this->requireLinks()->forSelector($invitation->selector ?? throw Invitation::invalid()),
            'expires_at' => gmdate(DATE_ATOM, $invitation->expiresAt),
        ];
    }

    private function requireLinks(): InvitationLink
    {
        return $this->links ?? throw BusinessException::notFound();
    }

    /** @param array<string, bool|int|string|null> $metadata */
    private function audit(string $action, Invitation $invitation, int $actorId, int $now, array $metadata = []): void
    {
        $this->audit->append($invitation->organizationId, $action, $actorId, null, [
            'invitation_id' => $invitation->id,
            'phone_masked' => $invitation->phone->masked(),
            'role' => $invitation->role->value,
            'result' => 'success',
        ] + $metadata, $now);
    }
}
