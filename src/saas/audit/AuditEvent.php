<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\audit;

final class AuditEvent
{
    public const PLATFORM_ORGANIZATION_ACTIONS = [
        'organization_created', 'organization_renamed', 'organization_owner_invitee_changed',
        'organization_opening_cancelled', 'organization_suspended', 'organization_unsuspended',
        'organization_archived', 'organization_restored', 'subscription_window_updated', 'subscription_package_changed',
    ];
    public const INVITATION_ACTIONS = ['invitation_created', 'invitation_link_retrieved', 'invitation_reissued', 'invitation_revoked'];

    private const VERIFICATION = [
        'sms_rate_limited' => 'rate_limited', 'email_rate_limited' => 'rate_limited',
        'sms_challenge_reissued' => 'reissued', 'email_challenge_reissued' => 'reissued',
        'sms_submitted' => 'submitted', 'email_submission_accepted' => 'submitted',
        'sms_submission_failed' => 'submission_failed', 'email_submission_failed' => 'submission_failed',
        'sms_submission_timeout' => 'submission_timeout', 'email_submission_timeout' => 'submission_timeout',
        'verification_code_invalid' => 'invalid', 'verification_attempts_exceeded' => 'attempts_exceeded',
    ];

    private const FIELDS = [
        'organization_created' => ['name', 'phase', 'package_id', 'seat_limit', 'starts_at', 'expires_at'],
        'organization_renamed' => ['old_name', 'new_name'],
        'organization_owner_invitee_changed' => ['previous_invitation_id', 'invitation_id', 'old_phone_masked', 'new_phone_masked', 'reason'],
        'organization_opening_cancelled' => ['phase', 'suspended'],
        'organization_archived' => ['phase', 'suspended'],
        'organization_restored' => ['phase', 'suspended'],
        'subscription_window_updated' => ['old_starts_at', 'old_expires_at', 'new_starts_at', 'new_expires_at'],
        'subscription_package_changed' => ['old_package_id', 'new_package_id', 'old_seat_limit', 'new_seat_limit'],
        'member_role_changed' => ['previous_role', 'new_role'],
        'member_removed' => ['previous_role'],
        'member_exited' => ['previous_role'],
        'ownership_transfer_started' => ['transfer_id', 'proposer_account_id'],
        'ownership_transfer_revoked' => ['transfer_id', 'proposer_account_id'],
        'ownership_transfer_completed' => ['transfer_id', 'proposer_account_id'],
        'ownership_transfer_expired' => ['transfer_id', 'proposer_account_id', 'expires_at'],
        'platform_admin_initialized' => ['actor_ref', 'phone_masked', 'account_created', 'source'],
        'platform_admin_granted' => ['phone_masked', 'account_created', 'source'],
        'platform_admin_revoked' => ['source'],
        'platform_admin_revoke_denied' => ['source', 'reason'],
        'package_created' => ['package_id', 'seat_limit'],
        'package_updated' => ['package_id', 'name_changed', 'old_seat_limit', 'new_seat_limit'],
        'package_retired' => ['package_id'],
        'phone_changed' => ['method', 'old_phone_masked', 'new_phone_masked', 'actor_ref', 'reason', 'evidence_channel', 'evidence_ref', 'reason_ref'],
        'email_bound' => ['purpose'],
        'email_changed' => ['purpose'],
        'password_reset' => ['purpose'],
        'member_contacts_exported' => ['kind', 'row_count'],
        'organization_audit_exported' => ['kind', 'row_count'],
    ];

    /** @return list<string> */
    public static function globalActions(): array
    {
        return [
            'platform_admin_initialized', 'platform_admin_granted', 'platform_admin_revoked', 'platform_admin_revoke_denied',
            'package_created', 'package_updated', 'package_retired', 'phone_changed', 'email_bound', 'email_changed', 'password_reset',
            ...array_keys(self::VERIFICATION),
        ];
    }

    /** @return list<string> */
    public static function organizationActions(): array
    {
        return [
            ...self::PLATFORM_ORGANIZATION_ACTIONS, ...self::INVITATION_ACTIONS, 'invitation_accepted',
            'member_role_changed', 'member_removed', 'member_exited',
            'ownership_transfer_started', 'ownership_transfer_revoked', 'ownership_transfer_completed', 'ownership_transfer_expired',
            'member_contacts_exported', 'organization_audit_exported',
            ...array_keys(self::VERIFICATION),
        ];
    }

    public static function project(object $row): array
    {
        $action = $row->action;
        $entry = json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR);
        $fields = match (true) {
            in_array($action, self::INVITATION_ACTIONS, true), $action === 'invitation_accepted' =>
                ['invitation_id', 'previous_invitation_id', 'phone_masked', 'role', 'source'],
            isset(self::VERIFICATION[$action]) => ['purpose', 'channel', 'invitation_id', 'phone_masked'],
            default => self::FIELDS[$action] ?? [],
        };
        $metadata = [];
        foreach ($fields as $field) {
            $value = $entry[$field] ?? null;
            if (self::validValue($field, $value)) {
                $metadata[$field] = $value;
            }
        }
        $result = match ($action) {
            'ownership_transfer_started' => 'started',
            'ownership_transfer_revoked' => 'revoked',
            'ownership_transfer_completed' => 'completed',
            'ownership_transfer_expired' => 'expired',
            'platform_admin_revoke_denied' => 'denied',
            'member_contacts_exported', 'organization_audit_exported' => ($entry['result'] ?? null) === 'failed' ? 'failed' : 'success',
            default => self::VERIFICATION[$action] ?? 'success',
        };

        return [
            'id' => (int) $row->id,
            'action' => $action,
            'organization_id' => $row->organization_id === null ? null : (int) $row->organization_id,
            'actor_account_id' => $row->actor_account_id === null ? null : (int) $row->actor_account_id,
            'target_account_id' => $row->target_account_id === null ? null : (int) $row->target_account_id,
            'created_at' => str_replace(' ', 'T', $row->created_at) . '+00:00',
            'result' => $result,
            'metadata' => (object) $metadata,
        ];
    }

    private static function validValue(string $field, mixed $value): bool
    {
        if (str_ends_with($field, '_masked')) {
            return is_string($value) && preg_match('/\A\*{10}[0-9]{4}\z/', $value) === 1;
        }
        if (str_ends_with($field, '_id') || str_ends_with($field, 'seat_limit') || $field === 'row_count') {
            return is_int($value) && $value >= 0;
        }
        if (str_ends_with($field, '_at')) {
            return ($field === 'expires_at' && is_int($value)) || (is_string($value)
                && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-][0-9]{2}:[0-9]{2}\z/', $value) === 1);
        }
        if (str_ends_with($field, '_ref')) {
            return is_string($value) && preg_match('/\A(?=[A-Za-z0-9._:-]*[A-Za-z])[A-Za-z0-9._:-]{1,80}\z/', $value) === 1
                && preg_match('/[0-9]{11}/', $value) !== 1;
        }

        return match ($field) {
            'name', 'old_name', 'new_name' => is_string($value) && strlen($value) <= 240
                && preg_match('/[@\x00-\x1f]|(?:[0-9][ .()-]*){11}|(?:https?|bearer)\s*[: ]/iu', $value) === 0,
            'phase' => in_array($value, ['active', 'pending'], true),
            'source' => in_array($value, ['api', 'cli', 'platform'], true),
            'role', 'previous_role', 'new_role' => in_array($value, ['owner', 'admin', 'member'], true),
            'purpose' => in_array($value, ['login', 'invitation', 'rebind', 'account_security', 'bind', 'change', 'reset', 'recovery'], true),
            'channel' => in_array($value, ['sms', 'email'], true),
            'method' => in_array($value, ['normal', 'email_recovery', 'manual_recovery', 'offline_recovery'], true),
            'reason' => in_array($value, ['wrong_number', 'recipient_changed', 'number_unavailable', 'other', 'lost_phone', 'compromised_phone', 'last_platform_admin_required'], true),
            'evidence_channel' => in_array($value, ['registered_contact', 'authorized_channel'], true),
            'kind' => in_array($value, ['member_contacts', 'organization_audit'], true),
            'account_created', 'name_changed', 'suspended' => is_bool($value),
            default => false,
        };
    }
}
