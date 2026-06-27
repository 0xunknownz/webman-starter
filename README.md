# webman-starter

Webman starter support package for common API response conventions, trace IDs, error codes, and business exception handling.

## Features

- Unified JSON API response envelope.
- `X-Trace-Id` propagation and UUID v7 fallback generation.
- Shared application error code constants.
- Business exception helpers for common HTTP failure cases.
- Webman exception handler that converts exceptions into API responses.
- Request-free response creation for CLI tests and library-level checks.

## Requirements

- PHP 8.1 or newer.
- A Webman application.
- Composer.

## Installation

Install the package in a Webman project:

```bash
composer require z0x/webman-starter
```

For local development, install dependencies from this repository:

```bash
composer install
```

## Usage

Return successful responses from controllers:

```php
<?php
declare(strict_types=1);

namespace app\controller;

use support\Request;
use Webman\Http\Response;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

final class UserController
{
    public function show(Request $request): Response
    {
        return ApiResponse::success([
            'id' => 1,
            'name' => 'Alice',
        ], request: $request);
    }
}
```

Create paginated responses:

```php
return ApiResponse::paginated(
    items: $users,
    page: 1,
    perPage: 20,
    total: 100,
    request: $request
);
```

Throw business exceptions from application or service code:

```php
use Z0x\WebmanStarter\Support\Exception\BusinessException;

throw BusinessException::validation([
    'phone' => ['手机号格式不正确'],
]);
```

Create custom business exceptions when the built-in helpers are not specific enough:

```php
throw BusinessException::make(
    message: '订单状态不允许取消',
    code: 300001,
    status: 409,
    data: ['order_id' => 123]
);
```

## Exception Handler

Register the package exception handler in the host Webman project:

```php
<?php

// config/exception.php
return [
    '' => Z0x\WebmanStarter\Support\Exception\Handler::class,
];
```

`BusinessException` is rendered with its business code, message, HTTP status, and optional field errors. Other uncaught exceptions are rendered as:

```json
{
  "code": 50001,
  "message": "服务内部错误",
  "data": null,
  "trace_id": "018f7c2e-..."
}
```

## API Response Format

Successful response:

```json
{
  "code": 0,
  "message": "success",
  "data": {},
  "trace_id": "018f7c2e-..."
}
```

Failed response:

```json
{
  "code": 40002,
  "message": "参数验证失败",
  "data": null,
  "trace_id": "018f7c2e-...",
  "errors": {
    "phone": ["手机号格式不正确"]
  }
}
```

See [docs/api-response.md](docs/api-response.md) for the full response contract and error code ranges.

## Project Structure

```text
src/
  support/
    exception/
      BusinessException.php
      Handler.php
    http/
      ApiResponse.php
      ErrorCode.php
      Pagination.php
      ResponseEnvelope.php
      TraceId.php
docs/
  api-response.md
```

## Development Conventions

- Follow Webman controller, service, domain, and infrastructure boundaries in host applications.
- Keep API response construction at the controller or exception-handler boundary.
- Use `BusinessException` for expected business failures.
- Keep domain code independent of Webman framework classes where possible.
- Use strict PHP typing and final classes by default.

## License

MIT
