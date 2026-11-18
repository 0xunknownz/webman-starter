<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\email;

use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class EmailAddress
{
    private function __construct(public readonly string $value)
    {
    }

    public static function fromInput(mixed $input): self
    {
        if (!is_string($input) || strlen($input) > 254 || preg_match('/[\x00-\x1F\x7F]/', $input) === 1
            || filter_var($input, FILTER_VALIDATE_EMAIL) === false) {
            throw new BusinessException('邮箱格式无效', 'email_invalid', 422);
        }

        return new self(strtolower($input));
    }
}
