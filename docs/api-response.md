# API Response

HTTP API responses use one JSON envelope at the controller boundary. Service and domain code should return business values or throw exceptions; they should not build API response arrays.

## Success

```json
{
  "code": 0,
  "message": "success",
  "data": {},
  "trace_id": "018f7c2e-..."
}
```

- `code = 0` means success.
- `message` defaults to `success`.
- `data` is always present. Use `null` for no returned data, `[]` for lists, and `{}` for objects.
- Created resources should use HTTP `201` with `code = 0`.
- Delete or no-content operations still return HTTP `200` with `data: null`; do not use `204`.

## Failure

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

- Non-zero `code` means failure.
- HTTP status still reflects protocol semantics, such as `401`, `403`, `404`, `422`, or `500`.
- `errors` is optional and uses `array<string, string[]>`. Use `_global` when an error has no field owner.
- Uncaught exceptions return `50001` and `服务内部错误`; detailed exception messages belong in logs.

## Pagination

```json
{
  "code": 0,
  "message": "success",
  "data": {
    "items": [],
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 0,
      "total_pages": 0
    }
  },
  "trace_id": "018f7c2e-..."
}
```

`total_pages` is calculated by `ApiResponse::paginated()`.

## Trace ID

Every response includes `trace_id` in the body and `X-Trace-Id` in the response header.

Resolution order:

1. Use a valid request header `X-Trace-Id`.
2. Otherwise generate a UUID v7 with `ramsey/uuid`.

Accepted inbound trace IDs are 8-128 characters and may contain letters, numbers, `.`, `_`, `:`, and `-`.

## Error Codes

```text
0       success

40000-49999 common client errors
40001   parameter error
40002   validation failed
40003   missing parameter
40101   unauthenticated
40102   token missing
40103   token invalid
40104   token expired
40301   forbidden
40401   not found
40402   route not found
40501   method not allowed
40901   conflict
41301   payload too large
41501   unsupported media type
42201   unprocessable entity
42901   too many requests

50000-59999 common server errors
50001   internal error
50201   bad gateway
50301   service unavailable
50401   gateway timeout

100000-199999 user/auth module
200000-299999 file/storage module
300000-399999 payment/order module
```

## Usage

```php
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

return ApiResponse::success(['id' => 1]);

return ApiResponse::created(['id' => 1]);

return ApiResponse::deleted();

return ApiResponse::paginated(
    items: $users,
    page: 1,
    perPage: 20,
    total: 100
);

throw BusinessException::validation([
    'phone' => ['手机号格式不正确'],
]);
```

Register the exception handler in the host Webman project:

```php
// config/exception.php
return [
    '' => Z0x\WebmanStarter\Support\Exception\Handler::class,
];
```
