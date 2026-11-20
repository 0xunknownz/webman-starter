<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\export;

use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\TraceId;

final class ExportOrganizationCsvController
{
    public function __construct(private readonly ExportOrganizationCsvService $exports)
    {
    }

    public function members(Request $request, string $organizationId): Response
    {
        return $this->download($request, 'members.csv', $this->exports->members($this->identity($request), $this->id($organizationId)));
    }

    public function audit(Request $request, string $organizationId): Response
    {
        return $this->download($request, 'audit-logs.csv', $this->exports->audit($this->identity($request), $this->id($organizationId)));
    }

    private function download(Request $request, string $filename, string $csv): Response
    {
        return new Response(200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            TraceId::HEADER => TraceId::fromRequest($request),
        ], $csv);
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
