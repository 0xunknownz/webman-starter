<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\profile;

use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class ProfileService
{
    public function __construct(
        private readonly ProfileRepositoryInterface $profiles,
        private readonly SessionService $sessions,
    ) {
    }

    /** @return array{account_id:int, display_name:?string, display_label:string} */
    public function get(int $accountId): array
    {
        return $this->withDisplayLabel($this->profiles->get($accountId));
    }

    /** @return array{account_id:int, display_name:?string, display_label:string} */
    public function update(SessionIdentity $identity, mixed $displayName, int $now): array
    {
        $name = $this->normalizeName($displayName);

        return $this->sessions->withCurrentIdentity($identity, $now, fn (): array =>
            $this->withDisplayLabel($this->profiles->update($identity->accountId, $name)));
    }

    /**
     * @param array{account_id:int, display_name:?string}|null $profile
     * @return array{account_id:int, display_name:?string, display_label:string}
     */
    private function withDisplayLabel(?array $profile): array
    {
        if ($profile === null) {
            throw BusinessException::unauthenticated();
        }

        $profile['display_label'] = $profile['display_name'] ?? '未设置姓名';

        return $profile;
    }

    private function normalizeName(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // The UTF-8 match also fails for malformed input. Reject controls before trimming.
        if (!is_string($value) || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) !== 0) {
            throw BusinessException::validation(['display_name' => ['显示名称格式无效']]);
        }

        $name = preg_replace('/\A\p{Zs}+|\p{Zs}+\z/u', '', $value);
        if ($name === null || preg_match('/\A.{1,50}\z/u', $name) !== 1) {
            throw BusinessException::validation(['display_name' => ['显示名称须为 1–50 个字符']]);
        }

        return $name;
    }
}
