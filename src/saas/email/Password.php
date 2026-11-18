<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\email;

use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class Password
{
    private const COST = 12;
    private const DUMMY_HASH = '$2y$12$h6wctjfY4tvx0JOEg5BfH.qbGrx3WFTYAKuNuekDrk2WkOFLPkv2.';

    public static function hash(#[\SensitiveParameter] mixed $input): string
    {
        if (!self::isValid($input)) {
            throw BusinessException::validation(['password' => ['密码必须包含 8 至 64 个字符，且不得超过 72 个字节或包含控制字符']]);
        }

        return password_hash($input, PASSWORD_BCRYPT, ['cost' => self::COST]);
    }

    public static function verify(#[\SensitiveParameter] mixed $input, #[\SensitiveParameter] ?string $hash): bool
    {
        $valid = self::isValid($input);
        $matches = password_verify($valid ? $input : '', $hash ?? self::DUMMY_HASH);

        return $valid && $hash !== null && $matches;
    }

    public static function isValid(#[\SensitiveParameter] mixed $input): bool
    {
        return is_string($input)
            && strlen($input) <= 72
            && preg_match('/\A[^\p{Cc}\p{Zl}\p{Zp}]{8,64}\z/u', $input) === 1
            && preg_match('/[^\p{Z}\s]/u', $input) === 1;
    }
}
