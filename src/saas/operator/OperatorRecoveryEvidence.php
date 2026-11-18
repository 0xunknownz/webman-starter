<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\operator;

use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class OperatorRecoveryEvidence
{
    private function __construct(
        public readonly string $channel,
        public readonly string $reference,
        public readonly string $reason,
    ) {
    }

    public static function fromInput(mixed $evidence, mixed $reason): self
    {
        if (!is_string($reason) || !in_array($reason, ['lost_phone', 'number_unavailable', 'compromised_phone'], true)) {
            throw BusinessException::validation(['reason' => ['请选择恢复原因']]);
        }
        if (!is_array($evidence) || count($evidence) !== 2
            || !is_string($channel = $evidence['channel'] ?? null)
            || !in_array($channel, ['registered_contact', 'authorized_channel'], true)
            || !is_string($reference = $evidence['reference'] ?? null)
            || preg_match('/\A(?=[A-Za-z0-9._-]*[A-Za-z])[A-Za-z0-9][A-Za-z0-9._-]{7,63}\z/', $reference) !== 1
            || preg_match('/[0-9]{11}/', $reference) === 1) {
            throw BusinessException::validation(['evidence' => ['请输入核验渠道和不含敏感数据的工单编号']]);
        }

        return new self($channel, $reference, $reason);
    }
}
