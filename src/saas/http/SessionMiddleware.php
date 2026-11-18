<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\http;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly ?string $purpose = null,
    ) {
    }

    public function process(Request $request, callable $handler): Response
    {
        $request->starterIdentity = $this->auth->controller()->identity($request, $this->purpose);

        return $handler($request);
    }
}
