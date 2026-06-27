<?php
declare(strict_types=1);

namespace Z0x\WebmanStarter\Support\Http;

final class ResponseEnvelope
{
    /**
     * @param array<string, array<int, string>>|null $errors
     */
    private function __construct(
        private readonly int $code,
        private readonly string $message,
        private readonly mixed $data,
        private readonly string $traceId,
        private readonly ?array $errors = null
    ) {
    }

    /**
     * @param array<string, array<int, string>>|null $errors
     */
    public static function create(
        int $code,
        string $message,
        mixed $data,
        string $traceId,
        ?array $errors = null
    ): self {
        return new self($code, $message, $data, $traceId, $errors);
    }

    /**
     * @return array{code: int, message: string, data: mixed, trace_id: string, errors?: array<string, array<int, string>>}
     */
    public function toArray(): array
    {
        $body = [
            'code' => $this->code,
            'message' => $this->message,
            'data' => $this->data,
            'trace_id' => $this->traceId,
        ];

        if ($this->errors !== null) {
            $body['errors'] = $this->errors;
        }

        return $body;
    }
}
