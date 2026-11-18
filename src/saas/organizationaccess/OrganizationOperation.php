<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organizationaccess;

/** Choose the operation in server code based on its effect, independent of the HTTP method. */
enum OrganizationOperation: string
{
    case BusinessQuery = 'business_query';
    case BusinessExport = 'business_export';
    case BusinessWrite = 'business_write';
    case MemberList = 'member_list';
    case MemberContacts = 'member_contacts';
    case OrganizationAudit = 'organization_audit';
    case MemberInvite = 'member_invite';
    case InvitationLink = 'invitation_link';
    case InvitationReissue = 'invitation_reissue';
    case InvitationRevoke = 'invitation_revoke';
    case MemberRemove = 'member_remove';
    case MemberExit = 'member_exit';
    case MemberRoleChange = 'member_role_change';
    case OwnershipTransferStart = 'ownership_transfer_start';
    case OwnershipTransferRevoke = 'ownership_transfer_revoke';
    case OwnershipTransferAccept = 'ownership_transfer_accept';
    case OrganizationArchive = 'organization_archive';

    public function isBusiness(): bool
    {
        return match ($this) {
            self::BusinessQuery, self::BusinessExport, self::BusinessWrite => true,
            default => false,
        };
    }

    public function allowedAfterExpiry(): bool
    {
        return match ($this) {
            self::BusinessQuery, self::BusinessExport, self::MemberList, self::MemberContacts, self::OrganizationAudit,
            self::InvitationRevoke, self::MemberRemove, self::MemberExit,
            self::OwnershipTransferStart, self::OwnershipTransferRevoke, self::OwnershipTransferAccept => true,
            default => false,
        };
    }

    public function allowsRole(OrganizationRole $role): bool
    {
        return match ($this) {
            self::OrganizationAudit, self::MemberRoleChange, self::OwnershipTransferStart, self::OwnershipTransferRevoke,
            self::OrganizationArchive => $role === OrganizationRole::Owner,
            self::MemberContacts, self::MemberInvite, self::InvitationLink, self::InvitationReissue,
            self::InvitationRevoke, self::MemberRemove => $role !== OrganizationRole::Member,
            self::MemberExit => $role !== OrganizationRole::Owner,
            // The transfer workflow checks the specific recipient and whether the pending request is still valid.
            default => true,
        };
    }
}
