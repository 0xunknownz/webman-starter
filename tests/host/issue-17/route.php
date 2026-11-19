<?php

declare(strict_types=1);

use Illuminate\Database\Query\Builder;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\Route;
use Z0x\WebmanStarter\saas\infrastructure\IlluminatePlatformAdminRepository;
use Z0x\WebmanStarter\saas\organization\IlluminateOrganizationRepository;
use Z0x\WebmanStarter\saas\organizationaccess\IlluminateOrganizationMembershipRepository;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccess;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccessService;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationOperation;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole;
use Z0x\WebmanStarter\saas\package\IlluminatePackageRepository;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

[$connection, $sessions] = issue17Dependencies();
$packages = new IlluminatePackageRepository($connection, new IlluminatePlatformAdminRepository($connection));
$organizations = new IlluminateOrganizationRepository($connection, $packages);
$access = new OrganizationAccessService($organizations, new IlluminateOrganizationMembershipRepository($connection), [
    'record.query' => [OrganizationRole::Admin, OrganizationRole::Member],
    'record.export' => [OrganizationRole::Admin],
    'record.write' => [OrganizationRole::Admin],
    'record.owner' => [],
]);
Route::disableDefaultRoute();
Route::get('/fixture/health', static fn (Request $request): Response => ApiResponse::success(['pid' => getmypid()], request: $request));

// Inputs to this route select test cases. A production route fixes the operation and permission in its handler.
Route::post('/fixture/organization/{organizationId:\d+}', static function (Request $request, string $organizationId) use (
    $access,
    $connection,
    $organizations,
    $sessions,
): Response {
    $now = (int) $request->post('now');
    $header = $request->header('authorization', '');
    if (preg_match('/\ABearer (\S+)\z/', $header, $match) !== 1) {
        throw BusinessException::unauthenticated();
    }
    // The module also checks purpose when callers authenticate without specifying one.
    $identity = $sessions->authenticate($match[1], null, $now);
    $operation = OrganizationOperation::from($request->post('operation'));
    $permission = $request->post('permission');
    $target = $request->post('record');
    $mode = $request->post('mode', '');
    $records = static fn (OrganizationAccess $context): Builder => $connection->table('fixture_records')
        ->where('organization_id', $context->organization->id);
    $contextData = static fn (OrganizationAccess $context): array => [
        'pid' => getmypid(), 'account' => $context->accountId,
        'organization' => $context->organization->id, 'role' => $context->role->value,
    ];

    if ($operation === OrganizationOperation::BusinessWrite) {
        if ($mode === 'revoked') {
            $sessions->logout($identity);
        }
        $data = $access->write($sessions, $identity, (int) $organizationId, $operation, $now,
            static function (OrganizationAccess $context) use ($target, $mode, $records, $contextData): array {
                $record = $records($context)->where('id', $target);
                if ($record->lockForUpdate()->first() === null) {
                    throw BusinessException::notFound();
                }
                $record->update(['payload' => 'updated']);
                if ($mode === 'exception') {
                    throw new RuntimeException('Fixture write failure');
                }

                return $contextData($context);
            }, $permission, clock: $request->post('locked_now') === null ? null : static fn (): int => (int) $request->post('locked_now'));
    } else {
        $context = $access->require($identity, (int) $organizationId, $operation, $now, $permission);
        $data = $contextData($context);
        if ($mode === 'capacity') {
            $phone = \Z0x\WebmanStarter\saas\domain\MainlandMobileNumber::fromInput('13900139000');
            $access->write($sessions, $identity, (int) $organizationId, $operation, $now,
                static function (OrganizationAccess $locked) use ($organizations, $phone, $now): void {
                    $organizations->assertInvitationCapacity($locked->organization, $phone, $now);
                }, beforeOrganizationLock: static fn (): ?int => $organizations->lockAccountByPhone($phone));
        }
        if ($target !== null) {
            $row = $records($context)->where('id', $target)->first();
            if ($row === null) {
                throw BusinessException::notFound();
            }
            $data['record'] = $row->payload;
        }
    }

    return ApiResponse::success($data, request: $request);
});

// A fixture-only credential for exercising the lifecycle gate after invite authentication.
Route::post('/fixture/invitation', static function (Request $request) use ($connection, $access): Response {
    $inviteId = (int) $request->post('invite');
    $proof = hash_hmac('sha256', (string) $inviteId, (string) getenv('ISSUE17_INVITATION'));
    if (!hash_equals('Bearer ' . $proof, (string) $request->header('authorization', ''))) {
        throw BusinessException::notFound();
    }
    $invitation = $connection->table('fixture_invites')->where('id', $inviteId)->first();
    if ($invitation === null) {
        throw BusinessException::notFound();
    }
    $organization = $access->requireInvitation((int) $invitation->organization_id,
        OrganizationRole::from($invitation->role), (int) $request->post('now'));

    return ApiResponse::success(['organization' => $organization->id, 'pid' => getmypid()], request: $request);
});
