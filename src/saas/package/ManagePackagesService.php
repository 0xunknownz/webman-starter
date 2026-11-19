<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\package;

use Closure;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class ManagePackagesService
{
    /** @param Closure():int $clock */
    public function __construct(
        private readonly PackageRepositoryInterface $packages,
        private readonly SessionService $sessions,
        private readonly Closure $clock,
    ) {
    }

    public function create(SessionIdentity $actor, mixed $name, mixed $seatLimit): PackageTemplate
    {
        return $this->authorized($actor, function () use ($actor, $name, $seatLimit): PackageTemplate {
            $template = $this->packages->create(PackageTemplate::normalizeName($name), PackageTemplate::validateSeatLimit($seatLimit));
            $this->packages->appendAudit($actor->accountId, $template->id, 'package_created', ['seat_limit' => $template->seatLimit], ($this->clock)());

            return $template;
        });
    }

    /** @param array{name?:mixed, seat_limit?:mixed} $fields */
    public function update(SessionIdentity $actor, int $packageId, array $fields): PackageTemplate
    {
        return $this->authorized($actor, function () use ($actor, $packageId, $fields): PackageTemplate {
            $hasName = array_key_exists('name', $fields);
            $hasSeatLimit = array_key_exists('seat_limit', $fields);
            if (!$hasName && !$hasSeatLimit) {
                throw new BusinessException('请提供套餐名称或席位数', 'package_invalid', 422);
            }
            $current = $this->packages->lock($packageId);
            $name = $hasName ? PackageTemplate::normalizeName($fields['name']) : $current->name;
            $seatLimit = $hasSeatLimit ? PackageTemplate::validateSeatLimit($fields['seat_limit']) : $current->seatLimit;
            $changes = [];
            if ($name !== $current->name) {
                // A free-text name may contain credentials or personal details. Audit only that it changed.
                $changes['name_changed'] = true;
            }
            if ($seatLimit !== $current->seatLimit) {
                $changes['old_seat_limit'] = $current->seatLimit;
                $changes['new_seat_limit'] = $seatLimit;
            }
            if ($changes === []) {
                return $current;
            }

            $updated = new PackageTemplate($packageId, $name, $seatLimit, $current->retired);
            $now = $this->sessions->liveTime($actor, $this->clock);
            $this->packages->update($updated);
            $this->packages->appendAudit($actor->accountId, $packageId, 'package_updated', $changes, $now);

            return $updated;
        });
    }

    public function retire(SessionIdentity $actor, int $packageId): PackageTemplate
    {
        return $this->authorized($actor, function () use ($actor, $packageId): PackageTemplate {
            $current = $this->packages->lock($packageId);
            if ($current->retired) {
                return $current;
            }

            $now = $this->sessions->liveTime($actor, $this->clock);
            $this->packages->retire($packageId, $now);
            $this->packages->appendAudit($actor->accountId, $packageId, 'package_retired', [], $now);

            return new PackageTemplate($packageId, $current->name, $current->seatLimit, true);
        });
    }

    /** @param Closure():PackageTemplate $operation */
    private function authorized(SessionIdentity $actor, Closure $operation): PackageTemplate
    {
        return $this->sessions->withPlatformWrite($actor, $this->clock, $this->packages->transaction(...), $operation);
    }
}
