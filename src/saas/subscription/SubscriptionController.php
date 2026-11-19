<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\subscription;

use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class SubscriptionController
{
    public function __construct(private readonly ManageSubscriptionsService $subscriptions)
    {
    }

    public function updateWindow(Request $request, SessionIdentity $actor, string $organizationId): Response
    {
        $id = self::id($organizationId);
        $window = $this->subscriptions->updateWindow($actor, $id, $request->post('starts_at'), $request->post('expires_at'));

        return ApiResponse::success([
            'organization_id' => $id,
            'starts_at' => $window->startsAt->format(DATE_ATOM),
            'expires_at' => $window->expiresAt->format(DATE_ATOM),
        ], request: $request);
    }

    public function switchPackage(Request $request, SessionIdentity $actor, string $organizationId): Response
    {
        $id = self::id($organizationId);
        $package = $this->subscriptions->switchPackage($actor, $id, $request->post('package_id'));

        return ApiResponse::success([
            'organization_id' => $id,
            'package_id' => $package->id,
            'package_name' => $package->name,
            'seat_limit' => $package->seatLimit,
        ], request: $request);
    }

    private static function id(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw BusinessException::notFound();
        }

        return $id;
    }
}
