<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organizationplatform;

use Closure;
use LogicException;
use SensitiveParameter;
use Z0x\WebmanStarter\saas\contract\OrganizationAuditInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\invitation\Invitation;
use Z0x\WebmanStarter\saas\invitation\InvitationLifecycle;
use Z0x\WebmanStarter\saas\invitation\InvitationRepositoryInterface;
use Z0x\WebmanStarter\saas\organization\Organization;
use Z0x\WebmanStarter\saas\organization\SubscriptionWindow;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccess;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccessService;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationOperation;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class ManagePlatformOrganizationsService
{
    private const CHANGE_REASONS = ['wrong_number', 'recipient_changed', 'number_unavailable', 'other'];

    /** @param Closure():int $clock */
    public function __construct(
        private readonly PlatformOrganizationRepositoryInterface $repository,
        private readonly InvitationRepositoryInterface $invitations,
        private readonly InvitationLifecycle $lifecycle,
        private readonly OrganizationAuditInterface $audit,
        private readonly SessionService $sessions,
        private readonly Closure $clock,
        private readonly ?OrganizationAccessService $access = null,
    ) {
    }

    public function open(
        SessionIdentity $actor,
        mixed $name,
        #[SensitiveParameter] mixed $phone,
        mixed $packageId,
        mixed $startsAt,
        mixed $expiresAt,
    ): array {
        return $this->authorized($actor, function () use ($actor, $name, $phone, $packageId, $startsAt, $expiresAt): array {
            $name = self::name($name);
            if (!is_int($packageId) || $packageId < 1) {
                throw BusinessException::validation(['package_id' => ['请选择套餐']]);
            }
            $subscription = SubscriptionWindow::fromInput($startsAt, $expiresAt);
            $number = self::number($phone);
            $this->lifecycle->lockAccountByPhone($number);
            $organization = $this->repository->create($name, $packageId, $subscription);
            $now = $this->sessions->liveTime($actor, $this->clock);
            $this->requireOwnerIssuance($organization, $now);
            $this->audit($organization, 'organization_created', $actor, $now, [
                'name' => $organization->name,
                'phase' => 'pending',
                'package_id' => $packageId,
                'seat_limit' => $organization->seatLimit,
                'starts_at' => $subscription->startsAt->format(DATE_ATOM),
                'expires_at' => $subscription->expiresAt->format(DATE_ATOM),
            ]);
            $invitation = $this->lifecycle->create($organization, $number, OrganizationRole::Owner, $actor->accountId, $now);

            return self::state($organization) + $this->lifecycle->linkData($invitation);
        });
    }

    public function rename(SessionIdentity $actor, int $organizationId, mixed $name): array
    {
        return $this->withOrganization($actor, $organizationId, function (Organization $organization, int $now) use ($actor, $name): array {
            $name = $this->repository->rename($organization, self::name($name));
            if ($name !== $organization->name) {
                $this->audit($organization, 'organization_renamed', $actor, $now, ['old_name' => $organization->name, 'new_name' => $name]);
            }

            return ['name' => $name] + self::state($organization);
        });
    }

    public function setSuspended(SessionIdentity $actor, int $organizationId, bool $suspended): array
    {
        return $this->withOrganization($actor, $organizationId, function (Organization $organization, int $now) use ($actor, $suspended): array {
            $this->requireNotArchived($organization);
            if ($organization->suspended !== $suspended) {
                $this->repository->setSuspended($organization->id, $suspended ? $now : null);
                $this->audit($organization, $suspended ? 'organization_suspended' : 'organization_unsuspended', $actor, $now);
            }

            return ['suspended' => $suspended] + self::state($organization);
        });
    }

    public function cancelOpening(SessionIdentity $actor, int $organizationId): array
    {
        return $this->withOrganization($actor, $organizationId, function (Organization $organization, int $now) use ($actor): array {
            $this->requireNotArchived($organization);
            $this->requirePending($organization);
            return $this->archiveLocked($organization, $actor, $now, 'organization_opening_cancelled');
        });
    }

    public function archive(SessionIdentity $actor, int $organizationId): array
    {
        if ($actor->purpose === SessionService::PURPOSE_ORGANIZATION) {
            $access = $this->access ?? throw new LogicException('Owner archival requires organization access checks.');

            return $access->write(
                $this->sessions,
                $actor,
                $organizationId,
                OrganizationOperation::OrganizationArchive,
                ($this->clock)(),
                function (OrganizationAccess $context) use ($actor): array {
                    $data = $this->archiveLocked($context->organization, $actor, $this->sessions->liveTime($actor, $this->clock), 'organization_archived');
                    $this->sessions->liveTime($actor, $this->clock);

                    return $data;
                },
                clock: $this->clock,
            );
        }

        return $this->withOrganization($actor, $organizationId, function (Organization $organization, int $now) use ($actor): array {
            $this->requireNotArchived($organization);

            return $this->archiveLocked($organization, $actor, $now, 'organization_archived');
        });
    }

    public function restore(SessionIdentity $actor, int $organizationId): array
    {
        return $this->withOrganization($actor, $organizationId, function (Organization $organization, int $now) use ($actor): array {
            if (!$organization->archived) {
                throw BusinessException::forbidden();
            }
            // Activation and suspension are stored independently and retained throughout archival.
            $this->repository->setArchived($organization->id, null);
            $state = ['archived' => false] + self::state($organization);
            $this->audit($organization, 'organization_restored', $actor, $now, [
                'phase' => $state['phase'], 'suspended' => $state['suspended'],
            ]);

            return $state;
        });
    }

    /** @return array{invitation_id:int, link:string, expires_at:string} */
    public function keep(SessionIdentity $actor, int $organizationId, int $invitationId): array
    {
        return $this->withOrganization($actor, $organizationId, function (Organization $organization, int $now) use ($actor, $invitationId): array {
            $this->requireOwnerIssuance($organization, $now);
            $invitation = $this->lockOwnerInvitation($organization->id, $invitationId);
            $now = $this->sessions->liveTime($actor, $this->clock);
            $this->requireOwnerIssuance($organization, $now);

            return $this->lifecycle->linkData($this->lifecycle->keep($invitation, $actor->accountId, $now));
        });
    }

    public function revoke(SessionIdentity $actor, int $organizationId, int $invitationId): void
    {
        $this->withOrganization($actor, $organizationId, function (Organization $organization) use ($actor, $invitationId): void {
            $this->requirePending($organization);
            $invitation = $this->lockOwnerInvitation($organization->id, $invitationId);
            $this->lifecycle->revoke($invitation, $actor->accountId, $this->sessions->liveTime($actor, $this->clock));
        });
    }

    public function revokeMemberInvitation(SessionIdentity $actor, int $organizationId, int $invitationId): void
    {
        $this->withOrganization($actor, $organizationId, function (Organization $organization) use ($actor, $invitationId): void {
            if (!$organization->suspended && !$organization->archived) {
                throw BusinessException::forbidden();
            }
            $invitation = $this->lifecycle->find($organization->id, $invitationId, lock: true);
            if ($invitation->role !== OrganizationRole::Member) {
                throw BusinessException::notFound();
            }
            $this->lifecycle->revoke($invitation, $actor->accountId, $this->sessions->liveTime($actor, $this->clock), platformOperation: true);
        });
    }

    /** @return array{invitation_id:int, link:string, expires_at:string} */
    public function reissue(SessionIdentity $actor, int $organizationId, int $invitationId): array
    {
        return $this->replace($actor, $organizationId, $invitationId, null);
    }

    /** @return array{invitation_id:int, link:string, expires_at:string} */
    public function changePhone(
        SessionIdentity $actor,
        int $organizationId,
        int $invitationId,
        #[SensitiveParameter] mixed $phone,
        #[SensitiveParameter] mixed $reason,
    ): array {
        return $this->replace($actor, $organizationId, $invitationId, static function () use ($phone, $reason): array {
            if (!is_string($reason) || !in_array($reason, self::CHANGE_REASONS, true)) {
                throw BusinessException::validation(['reason' => ['请选择改邀原因']]);
            }

            return [self::number($phone), $reason];
        });
    }

    /**
     * @param (Closure():array{MainlandMobileNumber, string})|null $changePhone
     * @return array{invitation_id:int, link:string, expires_at:string}
     */
    private function replace(SessionIdentity $actor, int $organizationId, int $invitationId, ?Closure $changePhone): array
    {
        return $this->authorized($actor, function () use ($actor, $organizationId, $invitationId, $changePhone): array {
            [$newPhone, $reason] = $changePhone === null ? [null, null] : $changePhone();
            // Discover only the phone to prelock. The selection and lifecycle checks use locked rows below.
            $hint = $this->invitations->find($organizationId, $invitationId);
            $phones = [];
            if ($hint?->role === OrganizationRole::Owner) {
                $phones[$hint->phone->e164] = $hint->phone;
            }
            if ($newPhone !== null) {
                $phones[$newPhone->e164] = $newPhone;
            }
            ksort($phones, SORT_STRING);
            foreach ($phones as $phone) {
                $this->lifecycle->lockAccountByPhone($phone);
            }
            $organization = $this->repository->lock($organizationId);
            $this->requireOwnerIssuance($organization, $this->sessions->liveTime($actor, $this->clock));
            $old = $this->lockOwnerInvitation($organizationId, $invitationId);
            if ($old->phone->e164 !== $hint?->phone->e164) {
                throw Invitation::invalid();
            }
            if ($newPhone !== null && $newPhone->e164 === $old->phone->e164) {
                throw BusinessException::validation(['phone' => ['请使用不同的受邀手机号']]);
            }
            $now = $this->sessions->liveTime($actor, $this->clock);
            $this->requireOwnerIssuance($organization, $now);
            $invitation = $this->lifecycle->replace($organization, $old, $newPhone ?? $old->phone, $actor->accountId, $now);
            if ($newPhone !== null) {
                $this->audit($organization, 'organization_owner_invitee_changed', $actor, $now, [
                    'previous_invitation_id' => $old->id,
                    'invitation_id' => $invitation->id,
                    'old_phone_masked' => $old->phone->masked(),
                    'new_phone_masked' => $newPhone->masked(),
                    'reason' => $reason,
                ]);
            }

            return $this->lifecycle->linkData($invitation);
        });
    }

    private function lockOwnerInvitation(int $organizationId, int $invitationId): Invitation
    {
        $latest = $this->invitations->latestOwner($organizationId, lock: true);
        if ($latest?->id === $invitationId) {
            return $latest;
        }
        $selected = $this->invitations->find($organizationId, $invitationId, lock: true);
        if ($selected?->role !== OrganizationRole::Owner) {
            throw BusinessException::notFound();
        }

        throw Invitation::invalid();
    }

    /** @template T @param Closure(Organization, int):T $operation @return T */
    private function withOrganization(SessionIdentity $actor, int $organizationId, Closure $operation): mixed
    {
        return $this->authorized($actor, function () use ($actor, $organizationId, $operation): mixed {
            $organization = $this->repository->lock($organizationId);

            return $operation($organization, $this->sessions->liveTime($actor, $this->clock));
        });
    }

    /** @template T @param Closure():T $operation @return T */
    private function authorized(SessionIdentity $actor, Closure $operation): mixed
    {
        return $this->sessions->withPlatformWrite($actor, $this->clock, $this->repository->transaction(...), $operation);
    }

    private function archiveLocked(Organization $organization, SessionIdentity $actor, int $now, string $action): array
    {
        $this->invitations->revokePending($organization->id);
        $this->repository->setArchived($organization->id, $now);
        $state = ['archived' => true] + self::state($organization);
        $this->audit($organization, $action, $actor, $now, [
            'phase' => $state['phase'], 'suspended' => $state['suspended'],
        ]);

        return $state;
    }

    private function requireOwnerIssuance(Organization $organization, int $now): void
    {
        $this->requireNotArchived($organization);
        if ($organization->suspended) {
            throw new BusinessException('组织已停用', 'organization_suspended', 403);
        }
        $this->requirePending($organization);
        if ($organization->subscription->statusAt($now) === SubscriptionWindow::EXPIRED) {
            throw new BusinessException('组织订阅已到期', 'subscription_expired', 403);
        }
    }

    private function requireNotArchived(Organization $organization): void
    {
        if ($organization->archived) {
            throw new BusinessException('组织已归档', 'organization_archived', 403);
        }
    }

    private function requirePending(Organization $organization): void
    {
        if ($organization->activated) {
            throw BusinessException::forbidden();
        }
    }

    /** @param array<string, bool|int|string|null> $metadata */
    private function audit(Organization $organization, string $action, SessionIdentity $actor, int $now, array $metadata = []): void
    {
        $this->audit->append($organization->id, $action, $actor->accountId, null, $metadata + ['result' => 'success'], $now);
    }

    /** @return array{organization_id:int, name:string, phase:string, suspended:bool, archived:bool} */
    private static function state(Organization $organization): array
    {
        return [
            'organization_id' => $organization->id,
            'name' => $organization->name,
            'phase' => $organization->activated ? 'active' : 'pending',
            'suspended' => $organization->suspended,
            'archived' => $organization->archived,
        ];
    }

    private static function name(mixed $name): string
    {
        if (!is_string($name)) {
            throw new BusinessException('名称无效', 'organization_name_invalid', 422);
        }

        return $name;
    }

    private static function number(#[SensitiveParameter] mixed $input): MainlandMobileNumber
    {
        if (!is_string($input) || strlen($input) > 250) {
            throw BusinessException::validation(['phone' => ['参数格式无效']]);
        }

        return MainlandMobileNumber::fromInput($input);
    }
}
