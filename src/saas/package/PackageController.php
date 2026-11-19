<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\package;

use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class PackageController
{
    public function __construct(private readonly ManagePackagesService $packages)
    {
    }

    public function create(Request $request, SessionIdentity $actor): Response
    {
        $template = $this->packages->create($actor, $request->post('name'), $request->post('seat_limit'));

        return ApiResponse::created(self::data($template), request: $request);
    }

    public function update(Request $request, SessionIdentity $actor, string $packageId): Response
    {
        $fields = array_intersect_key($request->post(), ['name' => true, 'seat_limit' => true]);
        $template = $this->packages->update($actor, self::id($packageId), $fields);

        return ApiResponse::success(self::data($template), request: $request);
    }

    public function retire(Request $request, SessionIdentity $actor, string $packageId): Response
    {
        $template = $this->packages->retire($actor, self::id($packageId));

        return ApiResponse::success(self::data($template), request: $request);
    }

    /** @return array{package_id:int, name:string, seat_limit:int, retired:bool} */
    private static function data(PackageTemplate $template): array
    {
        return ['package_id' => $template->id, 'name' => $template->name, 'seat_limit' => $template->seatLimit, 'retired' => $template->retired];
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
