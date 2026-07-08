# API Response

HTTP API responses use one JSON envelope at the controller boundary. Service and domain code should return business values or throw exceptions; they should not build API response arrays.

## Success

```json
{
  "code": "success",
  "message": "success",
  "data": {},
  "trace_id": "018f7c2e-..."
}
```

- `code = "success"` means success.
- `message` defaults to `success`.
- `data` is always present. Use `null` for no returned data, `[]` for lists, and `{}` for objects.
- Created resources should use HTTP `201` with `code = "success"`.
- Delete or no-content operations still return HTTP `200` with `data: null`; do not use `204`.

## Failure

```json
{
  "code": "validation_failed",
  "message": "参数验证失败",
  "data": null,
  "trace_id": "018f7c2e-...",
  "errors": {
    "phone": ["手机号格式不正确"]
  }
}
```

- Any `code` other than `"success"` means failure.
- HTTP status still reflects protocol semantics, such as `401`, `403`, `404`, `422`, or `500`.
- `errors` is optional and uses `array<string, string[]>`. Use `_global` when an error has no field owner.
- Uncaught exceptions return `"internal_error"` and `服务内部错误`; detailed exception messages belong in logs.

## Pagination

```json
{
  "code": "success",
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

`page`, `per_page`, and `total` are normalized to safe values before the response is built:

- `page` is at least `1`.
- `per_page` is at least `1`.
- `total` is at least `0`.

## Trace ID

Every response includes `trace_id` in the body and `X-Trace-Id` in the response header.

Resolution order:

1. Use a valid request header `X-Trace-Id`.
2. Otherwise generate a UUID v7 with `ramsey/uuid`.

When no `Request` is provided, responses still receive a generated trace ID. This keeps controller tests and CLI-level contract checks independent from Webman's active request context.

Accepted inbound trace IDs are 8-128 characters and may contain letters, numbers, `.`, `_`, `:`, and `-`.

## Error Codes

```text
success                    success

Common client errors
parameter_error            parameter error
validation_failed          validation failed
missing_parameter          missing parameter
unauthenticated            unauthenticated
token_missing              token missing
token_invalid              token invalid
token_expired              token expired
forbidden                  forbidden
not_found                  not found
route_not_found            route not found
method_not_allowed         method not allowed
conflict                   conflict
payload_too_large          payload too large
unsupported_media_type     unsupported media type
unprocessable_entity       unprocessable entity
too_many_requests          too many requests

Common server errors
internal_error             internal error
bad_gateway                bad gateway
service_unavailable        service unavailable
gateway_timeout            gateway timeout

Custom business codes should use stable snake_case strings, for example:
user_auth_failed
file_upload_rejected
order_status_not_cancelable
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

throw BusinessException::tokenExpired();

throw BusinessException::make(
    message: '订单状态不允许取消',
    code: 'order_status_not_cancelable',
    status: 409,
    data: ['order_id' => 123]
);
```

Register the exception handler in the host Webman project:

```php
// config/exception.php
return [
    '' => Z0x\WebmanStarter\Support\Exception\Handler::class,
];
```
