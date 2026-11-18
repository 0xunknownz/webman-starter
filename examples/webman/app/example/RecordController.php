<?php

declare(strict_types=1);

namespace app\example;

use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class RecordController
{
    public function __construct(private readonly ManageRecordsService $records)
    {
    }

    public function list(Request $request, string $organizationId): Response
    {
        $records = $this->records->list($this->identity($request), $this->id($organizationId));

        return ApiResponse::success(['records' => $records], request: $request)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $organizationId, string $recordId): Response
    {
        $record = $this->records->show($this->identity($request), $this->id($organizationId), $this->id($recordId));

        return ApiResponse::success($record, request: $request)->header('Cache-Control', 'no-store');
    }

    public function create(Request $request, string $organizationId): Response
    {
        $record = $this->records->create($this->identity($request), $this->id($organizationId), $request->post('title'));

        return ApiResponse::created($record, request: $request)->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $organizationId, string $recordId): Response
    {
        $record = $this->records->update(
            $this->identity($request),
            $this->id($organizationId),
            $this->id($recordId),
            $request->post('title'),
        );

        return ApiResponse::success($record, request: $request)->header('Cache-Control', 'no-store');
    }

    private function identity(Request $request): SessionIdentity
    {
        $identity = $request->starterIdentity ?? null;
        if (!$identity instanceof SessionIdentity) {
            throw BusinessException::unauthenticated();
        }

        return $identity;
    }

    private function id(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw BusinessException::notFound();
        }

        return $id;
    }
}
