<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\profile;

use Closure;
use Webman\Http\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class ProfileController
{
    /** @param Closure():int $clock */
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly Closure $clock,
    ) {
    }

    public function get(Request $request, SessionIdentity $identity): Response
    {
        return ApiResponse::success($this->profiles->get($identity->accountId), request: $request)
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, SessionIdentity $identity): Response
    {
        $input = $this->body($request);
        if (!array_key_exists('display_name', $input)) {
            return $this->get($request, $identity);
        }

        return ApiResponse::success($this->profiles->update($identity, $input['display_name'], ($this->clock)()), request: $request)
            ->header('Cache-Control', 'no-store');
    }

    /** @return array<array-key, mixed> */
    private function body(Request $request): array
    {
        if (!str_contains(strtolower((string) $request->header('content-type', '')), 'json')) {
            return $request->post();
        }

        $raw = $request->rawBody();
        if ($raw === '') {
            return [];
        }

        // Workerman's post() treats malformed JSON (including invalid UTF-8) as an empty body.
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            throw BusinessException::validation(['display_name' => ['请求体格式无效']]);
        }

        return $body;
    }
}
